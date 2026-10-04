<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use App\Models\Participant;
use App\Models\Student;
use App\Models\Team;
use Carbon\CarbonInterface;
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

    public function gradebook(Team $currentTeam): StreamedResponse
    {
        $assessments = $currentTeam->assessments()
            ->whereIn('status', [AssessmentStatus::Published, AssessmentStatus::Archived])
            ->orderBy('closes_at')
            ->get();

        // Students only see an assessment's scores once its results are released.
        $released = $assessments->mapWithKeys(fn (Assessment $assessment) => [$assessment->id => $assessment->resultsReleased()]);
        $ids = $assessments->modelKeys();

        return $this->stream(Str::slug($currentTeam->name).'-gradebook.csv', function ($out) use ($currentTeam, $assessments, $released, $ids) {
            fputcsv($out, ['# Final scores students can see. Blank = not taken, not graded, under review, or results not released.'], escape: '');
            fputcsv($out, [
                'roll_number', 'name',
                ...$assessments->map(fn (Assessment $assessment) => $this->safe("{$assessment->title} ({$assessment->type->value})"))->all(),
                'total',
            ], escape: '');

            $currentTeam->students()
                ->orderBy('roll_number')
                ->with(['participants' => fn ($query) => $query->whereIn('assessment_id', $ids)->with([
                    'attempt:id,participant_id,status,score',
                    'attempt.answers:id,attempt_id,published_at',
                    'currentSubmission:id,participant_id,status,score,published_at',
                ])])
                ->lazy(200)
                ->each(function (Student $student) use ($out, $assessments, $released) {
                    $participants = $student->participants->keyBy('assessment_id');

                    $scores = $assessments->map(fn (Assessment $assessment) => $released[$assessment->id]
                        ? $this->publishedScore($participants->get($assessment->id), $assessment)
                        : null);

                    fputcsv($out, [
                        $this->safe($student->roll_number),
                        $this->safe($student->name),
                        ...$scores->map(fn (?float $score) => $score === null ? '' : $score)->all(),
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
     * The final score a student can see, or null.
     */
    private function publishedScore(?Participant $participant, Assessment $assessment): ?float
    {
        if ($participant === null) {
            return null;
        }

        if ($assessment->isAssignment()) {
            $submission = $participant->currentSubmission;

            return $submission && $submission->status === SubmissionStatus::Final && $submission->published_at !== null && $submission->score !== null
                ? (float) $submission->score
                : null;
        }

        $attempt = $participant->attempt;

        return $attempt && $attempt->status === AttemptStatus::Graded && $attempt->score !== null
            && $attempt->answers->every(fn (Answer $answer) => $answer->published_at !== null)
            ? (float) $attempt->score
            : null;
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
