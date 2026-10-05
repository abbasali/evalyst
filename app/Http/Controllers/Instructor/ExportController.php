<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AssessmentStatus;
use App\Grading\ParticipantScore;
use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use App\Models\Participant;
use App\Models\Student;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV exports (UTF-8 with a BOM so Excel opens them correctly). Instructor exports
 * show every score with a status column; the gradebook shows published scores only.
 */
class ExportController extends Controller
{
    public function assessment(Team $currentTeam, Assessment $assessment): StreamedResponse
    {
        $timezone = $currentTeam->timezone;
        $time = fn (?CarbonInterface $value) => $value?->copy()->setTimezone($timezone)->toIso8601String() ?? '';

        return $assessment->isAssignment()
            ? $this->assignmentCsv($assessment, $time)
            : $this->quizCsv($assessment, $time);
    }

    /**
     * Final scores students can see: published, and only once results are released.
     */
    public function gradebook(Team $currentTeam): StreamedResponse
    {
        return $this->scoreSheet(
            $currentTeam,
            Str::slug($currentTeam->name).'-gradebook.csv',
            '# Final scores students can see. Blank = not taken, not graded, under review, or results not released.',
            fn (?Participant $participant, Assessment $assessment, bool $released): ?float => $released
                ? ParticipantScore::published($participant, $assessment->isAssignment())
                : null,
            [
                'attempt:id,participant_id,status,score',
                'attempt.answers:id,attempt_id,published_at',
                'currentSubmission:id,participant_id,status,score,published_at',
            ],
        );
    }

    /**
     * Every finished grade, released to students or not.
     */
    public function scores(Team $currentTeam): StreamedResponse
    {
        return $this->scoreSheet(
            $currentTeam,
            Str::slug($currentTeam->name).'-scores.csv',
            '# Graded scores, whether or not results are released. Blank = not taken, still grading, or under review.',
            fn (?Participant $participant, Assessment $assessment): ?float => ParticipantScore::graded($participant, $assessment->isAssignment()),
            ['attempt:id,participant_id,status,score', 'currentSubmission:id,participant_id,status,score'],
        );
    }

    /**
     * One row per student, one column per published or archived assessment, then a total.
     *
     * @param  callable(?Participant, Assessment, bool): ?float  $score
     * @param  array<int, string>  $relations  participant relations $score reads
     */
    private function scoreSheet(Team $team, string $filename, string $note, callable $score, array $relations): StreamedResponse
    {
        $assessments = $team->assessments()
            ->whereIn('status', [AssessmentStatus::Published, AssessmentStatus::Archived])
            ->orderBy('closes_at')
            ->get();

        /** @var Collection<int, bool> $released */
        $released = $assessments->mapWithKeys(fn (Assessment $assessment) => [$assessment->id => $assessment->resultsReleased()]);
        $ids = $assessments->modelKeys();

        return $this->stream($filename, function ($out) use ($team, $note, $score, $relations, $assessments, $released, $ids) {
            fputcsv($out, [$note], escape: '');
            fputcsv($out, [
                'roll_number', 'name',
                ...$assessments->map(fn (Assessment $assessment) => $this->safe("{$assessment->title} ({$assessment->type->value})"))->all(),
                'total',
            ], escape: '');

            $team->students()
                ->orderBy('roll_number')
                ->with(['participants' => fn ($query) => $query->whereIn('assessment_id', $ids)->with($relations)])
                ->lazy(200)
                ->each(function (Student $student) use ($out, $score, $assessments, $released) {
                    $participants = $student->participants->keyBy('assessment_id');

                    $scores = $assessments->map(fn (Assessment $assessment) => $score($participants->get($assessment->id), $assessment, $released[$assessment->id]));

                    fputcsv($out, [
                        $this->safe($student->roll_number),
                        $this->safe($student->name),
                        ...$scores->map(fn (?float $value) => $value === null ? '' : $value)->all(),
                        round($scores->filter()->sum(), 2),
                    ], escape: '');
                });
        });
    }

    /**
     * @param  callable(?CarbonInterface): string  $time
     */
    private function quizCsv(Assessment $quiz, callable $time): StreamedResponse
    {
        $items = $quiz->assessmentQuestions()->orderBy('position')->get(['id', 'position']);

        return $this->stream(Str::slug($quiz->title).'-results.csv', function ($out) use ($quiz, $items, $time) {
            fputcsv($out, [
                'roll_number', 'name', 'status', 'started_at', 'submitted_at', 'auto_submitted', 'focus_lost',
                ...$items->map(fn ($item) => "Q{$item->position}")->all(), 'total', 'max',
            ], escape: '');

            $quiz->participants()->with(['student', 'attempt.answers:id,attempt_id,assessment_question_id,score'])->lazyById(200)
                ->each(function (Participant $participant) use ($out, $items, $time) {
                    $attempt = $participant->attempt;
                    $scores = $attempt?->answers->keyBy('assessment_question_id');

                    fputcsv($out, [
                        $this->safe($participant->student->roll_number),
                        $this->safe($participant->student->name),
                        $attempt->status->value ?? 'not_started',
                        $time($attempt?->started_at),
                        $time($attempt?->submitted_at),
                        $attempt ? ($attempt->auto_submitted ? 'yes' : 'no') : '',
                        $attempt->focus_lost_count ?? '',
                        ...$items->map(function ($item) use ($scores) {
                            /** @var Answer|null $answer */
                            $answer = $scores?->get($item->id);

                            return $this->number($answer?->score);
                        })->all(),
                        $this->number($attempt?->score),
                        $this->number($attempt?->max_score),
                    ], escape: '');
                });
        });
    }

    /**
     * @param  callable(?CarbonInterface): string  $time
     */
    private function assignmentCsv(Assessment $assignment, callable $time): StreamedResponse
    {
        $rules = $assignment->rules()->get(['id', 'title', 'position']);

        return $this->stream(Str::slug($assignment->title).'-results.csv', function ($out) use ($assignment, $rules, $time) {
            fputcsv($out, [
                'roll_number', 'name', 'repo_url', 'commit_sha', 'submitted_at', 'minutes_late',
                ...$rules->map(fn (AssignmentRule $rule) => $this->safe($rule->title))->all(),
                'raw_score', 'penalty', 'score', 'max', 'status',
            ], escape: '');

            $assignment->participants()->with(['student', 'currentSubmission.ruleResults:id,submission_id,assignment_rule_id,score'])->lazyById(200)
                ->each(function (Participant $participant) use ($out, $rules, $time) {
                    $submission = $participant->currentSubmission;
                    $results = $submission?->ruleResults->keyBy('assignment_rule_id');

                    fputcsv($out, [
                        $this->safe($participant->student->roll_number),
                        $this->safe($participant->student->name),
                        $submission->repo_url ?? '',
                        $submission->commit_sha ?? '',
                        $time($submission?->submitted_at),
                        $submission->minutes_late ?? '',
                        ...$rules->map(fn (AssignmentRule $rule) => $this->number($results?->get($rule->id)?->score))->all(),
                        $this->number($submission?->raw_score),
                        $this->number($submission?->penalty),
                        $this->number($submission?->score),
                        $this->number($submission?->max_score),
                        $submission->status->value ?? 'not_submitted',
                    ], escape: '');
                });
        });
    }

    /**
     * @param  callable(resource): void  $write
     */
    private function stream(string $filename, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            $write($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Decimal columns as plain numbers (2.50 → 2.5), blank when missing.
     */
    private function number(?string $value): float|string
    {
        return $value === null ? '' : (float) $value;
    }

    /**
     * Stop spreadsheet apps from treating a value as a formula.
     */
    private function safe(string $value): string
    {
        return in_array(substr(ltrim($value, ' '), 0, 1), ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
