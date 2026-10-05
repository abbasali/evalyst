<?php

namespace App\Http\Controllers\Student;

use App\Enums\AttemptStatus;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\AssignmentRule;
use App\Models\Participant;
use App\Support\AnswerPresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A student's private results link (the participant ULID is the secret; no session needed).
 * Only published grades are ever sent, and only after results are released.
 */
class ResultsController extends Controller
{
    public function show(Participant $participant): Response
    {
        $assessment = $participant->assessment;

        if ($assessment->isAssignment()) {
            return $this->assignment($participant);
        }

        $attempt = $participant->attempt;
        $released = $attempt !== null && $attempt->status !== AttemptStatus::InProgress && $assessment->resultsReleased();

        $answers = $released ? AnswerPresenter::inOrder($attempt) : collect();
        $items = $answers->map(fn (Answer $answer) => AnswerPresenter::forStudent($answer, $attempt, $assessment->show_answers_after_release))->values();
        $allPublished = $released && $items->every(fn (array $item) => $item['published']);

        return Inertia::render('student/Results', [
            'assessment' => [
                'title' => $assessment->title,
                'timezone' => $assessment->team->timezone,
            ],
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'submittedAt' => $attempt?->submitted_at?->toIso8601String(),
            'started' => $attempt !== null,
            'resultsHidden' => ! $assessment->release_results,
            'released' => $released,
            'items' => $items,
            'total' => $allPublished ? round($items->sum('score'), 2) : null,
            'maxScore' => $released ? (float) $attempt->max_score : null,
        ]);
    }

    /**
     * Assignment results: per-rule scores and reasoning, feedback and the late penalty, only
     * once results are released and the submission's grade is published.
     */
    private function assignment(Participant $participant): Response
    {
        $assessment = $participant->assessment;
        $submission = $participant->currentSubmission()->with('ruleResults')->first();
        $released = $submission !== null && $assessment->resultsReleased();
        $published = $released && $submission->published_at !== null && $submission->status === SubmissionStatus::Final;
        $results = $published ? $submission->ruleResults->keyBy('assignment_rule_id') : collect();

        return Inertia::render('student/AssignmentResults', [
            'assessment' => ['title' => $assessment->title, 'timezone' => $assessment->team->timezone],
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'submission' => $submission ? [
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'short_sha' => $submission->shortSha(),
                'commit_url' => $submission->commitUrl(),
            ] : null,
            'resultsHidden' => ! $assessment->release_results,
            'released' => $released,
            'published' => $published,
            'grade' => $published ? [
                'rules' => $assessment->rules()->get()->map(fn (AssignmentRule $rule) => [
                    'title' => $rule->title,
                    'score' => (float) ($results->get($rule->id)->score ?? 0),
                    'max_score' => (float) $rule->marks,
                    'reasoning' => $results->get($rule->id)?->reasoning,
                ]),
                'feedback' => $submission->feedback,
                'raw_score' => (float) $submission->raw_score,
                'penalty' => (float) $submission->penalty,
                'minutes_late' => $submission->minutes_late,
                'score' => (float) $submission->score,
                'max_score' => (float) $submission->max_score,
            ] : null,
        ]);
    }
}
