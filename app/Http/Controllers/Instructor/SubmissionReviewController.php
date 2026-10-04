<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assignments\PublishSubmission;
use App\Actions\Assignments\RegradeSubmissions;
use App\Grading\LatePenaltyCalculator;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Submission;
use App\Models\SubmissionRuleResult;
use App\Models\Team;
use App\Queries\ReviewInboxQuery;
use App\Support\AnswerPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reviewing one submission: rule-by-rule scores, overrides, publish and regrade.
 */
class SubmissionReviewController extends Controller
{
    public function show(Team $currentTeam, Submission $submission): Response
    {
        $submission->load(['participant.student', 'participant.assessment', 'ruleResults.rule', 'grader:id,name', 'auditLogs.user:id,name']);
        $participant = $submission->participant;
        $assessment = $participant->assessment;
        $calculator = LatePenaltyCalculator::for($assessment, $participant);
        $results = $submission->ruleResults->keyBy('assignment_rule_id');

        return Inertia::render('review/Submission', [
            'submission' => [
                'id' => $submission->id,
                'status' => $submission->status->value,
                'is_current' => $submission->is_current,
                'repo_url' => $submission->repo_url,
                'commit_url' => $submission->commitUrl(),
                'short_sha' => $submission->shortSha(),
                'sha' => $submission->commit_sha,
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'minutes_late' => $submission->minutes_late,
                'raw_score' => $submission->raw_score !== null ? (float) $submission->raw_score : null,
                'penalty' => (float) $submission->penalty,
                'score' => $submission->score !== null ? (float) $submission->score : null,
                'max_score' => (float) $submission->max_score,
                'feedback' => $submission->feedback,
                'flags' => $submission->ai_flags ?? [],
                'reasons' => $submission->review_reasons ?? [],
                'error' => $submission->error,
                'published_at' => $submission->published_at?->toIso8601String(),
                'graded_by' => $submission->grader?->name,
                'manifest' => $submission->manifest === null ? null : [
                    ...$submission->manifest,
                    // Repos with committed assets can skip thousands of files; the page shows a sample.
                    'skipped' => array_slice((array) ($submission->manifest['skipped'] ?? []), 0, 200, true),
                    'skipped_count' => count((array) ($submission->manifest['skipped'] ?? [])),
                ],
            ],
            'rules' => $assessment->rules()->get()->map(function ($rule) use ($results) {
                /** @var SubmissionRuleResult|null $result */
                $result = $results->get($rule->id);

                return [
                    'id' => $rule->id,
                    'result_id' => $result?->id,
                    'kind' => $rule->kind->value,
                    'title' => $rule->title,
                    'description' => $rule->description,
                    'max_score' => (float) $rule->marks,
                    'score' => $result ? (float) $result->score : null,
                    'passed' => $result?->passed,
                    'reasoning' => $result?->reasoning,
                    'evidence' => $result->evidence ?? [],
                    'confidence' => $result?->ai_confidence !== null ? (float) $result->ai_confidence : null,
                    'overridden' => $result?->overridden_by !== null,
                ];
            }),
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'assignment' => ['id' => $assessment->id, 'title' => $assessment->title],
            'late' => [
                'deadline' => $calculator->effectiveDeadline()->toIso8601String(),
                'waived' => $participant->penalty_waived,
                'override' => $participant->penalty_override !== null ? (float) $participant->penalty_override : null,
                'note' => $participant->override_note,
            ],
            'audit' => AnswerPresenter::audit($submission->auditLogs),
        ]);
    }

    public function update(Team $currentTeam, Submission $submission, Request $request, PublishSubmission $publish): RedirectResponse
    {
        $data = $request->validate([
            'rules' => ['array'],
            'rules.*.score' => ['required', 'numeric', 'min:0'],
            'rules.*.reasoning' => ['nullable', 'string', 'max:5000'],
            'feedback' => ['nullable', 'string', 'max:10000'],
        ]);

        $publish->handle($request->user(), $submission, array_map(
            fn (array $rule) => ['score' => (float) $rule['score'], 'reasoning' => $rule['reasoning'] ?? null],
            $data['rules'] ?? [],
        ), $request->has('feedback') ? (string) ($data['feedback'] ?? '') : null);

        return $this->done($currentTeam, __('Grade published.'));
    }

    public function regrade(Team $currentTeam, Submission $submission, Request $request, RegradeSubmissions $regrade): RedirectResponse
    {
        if ($regrade->handle($request->user(), [$submission]) === 0) {
            throw ValidationException::withMessages(['decision' => __('This submission is already being graded, or a newer one replaced it.')]);
        }

        return $this->done($currentTeam, __('Grading again at the same commit.'));
    }

    /**
     * "Regrade all" on an assignment, e.g. after its rules changed.
     */
    public function regradeAll(Team $currentTeam, Assessment $assignment, Request $request, RegradeSubmissions $regrade): RedirectResponse
    {
        $count = $regrade->handle($request->user(), $assignment->submissions()->where('is_current', true)->lazyById());

        return $this->done($currentTeam, trans_choice('{0} Nothing to regrade.|{1} Regrading 1 submission.|[2,*] Regrading :count submissions.', $count, ['count' => $count]));
    }

    private function done(Team $team, string $message): RedirectResponse
    {
        ReviewInboxQuery::forgetCount($team->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
