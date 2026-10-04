<?php

namespace App\Actions\Assignments;

use App\Actions\Audit\RecordAudit;
use App\Enums\SubmissionStatus;
use App\Grading\LatePenaltyCalculator;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishSubmission
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * Publish a submission's grade, optionally with the instructor's rule scores and feedback.
     * Raw score and final score are recalculated with the late penalty. One audit log.
     *
     * @param  array<int, array{score: float, reasoning?: string|null}>  $ruleOverrides  rule result id => values
     * @param  string|null  $feedback  New feedback ('' clears it), or null to keep the current feedback.
     */
    public function handle(User $user, Submission $submission, array $ruleOverrides = [], ?string $feedback = null): void
    {
        DB::transaction(function () use ($user, $submission, $ruleOverrides, $feedback) {
            $locked = Submission::query()->with(['ruleResults.rule', 'participant.assessment'])->whereKey($submission->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_current) {
                throw ValidationException::withMessages(['decision' => __('The student has submitted again since; review the newer submission.')]);
            }

            if (in_array($locked->status, [SubmissionStatus::Submitted, SubmissionStatus::Grading], true)) {
                throw ValidationException::withMessages(['decision' => __('This submission is still being graded.')]);
            }

            $ruleIds = $locked->participant->assessment->rules()->pluck('id');

            if ($ruleIds->diff($locked->ruleResults->pluck('assignment_rule_id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['decision' => __('Some rules have no score yet. Retry grading first.')]);
            }

            $before = ['score' => $locked->score !== null ? (float) $locked->score : null, 'feedback' => $locked->feedback, 'rules' => []];
            $after = ['rules' => []];

            foreach ($locked->ruleResults as $result) {
                // Marks may have changed since grading: the rule's current marks are the maximum.
                $max = (float) $result->rule->marks;

                if ((float) $result->max_score !== $max) {
                    $result->update(['max_score' => $max]);
                }

                $override = $ruleOverrides[$result->id] ?? null;

                if ($override === null) {
                    if ((float) $result->score > $max) {
                        throw ValidationException::withMessages(["rules.{$result->id}.score" => __('Between 0 and :max.', ['max' => $max])]);
                    }

                    continue;
                }

                $score = round((float) $override['score'], 2);

                if ($score < 0 || $score > $max) {
                    throw ValidationException::withMessages(["rules.{$result->id}.score" => __('Between 0 and :max.', ['max' => $max])]);
                }

                $reasoning = array_key_exists('reasoning', $override) ? $override['reasoning'] : $result->reasoning;

                if ($score !== (float) $result->score || $reasoning !== $result->reasoning) {
                    $before['rules'][$result->id] = (float) $result->score;
                    $after['rules'][$result->id] = $score;
                    $result->update(['score' => $score, 'reasoning' => $reasoning, 'overridden_by' => $user->id]);
                }
            }

            $raw = round((float) $locked->ruleResults()->sum('score'), 2);
            $calculator = LatePenaltyCalculator::for($locked->participant->assessment, $locked->participant);
            $penalty = $calculator->penalty($calculator->minutesLate($locked->submitted_at));
            $newFeedback = $feedback === null ? $locked->feedback : (trim($feedback) === '' ? null : $feedback);
            $overridden = $after['rules'] !== [] || $newFeedback !== $locked->feedback;

            $locked->update([
                'raw_score' => $raw,
                'penalty' => $penalty,
                'score' => LatePenaltyCalculator::finalScore($raw, $penalty),
                'max_score' => $locked->participant->assessment->maxScore(),
                'feedback' => $newFeedback,
                'status' => SubmissionStatus::Final,
                'review_reasons' => null,
                'error' => null,
                'graded_by' => $user->id,
                'published_at' => now(),
            ]);

            $this->audit->handle($user, $locked, $overridden ? 'grade.override' : 'grade.accept', [
                'before' => $before,
                'after' => [...$after, 'score' => (float) $locked->score, 'feedback' => $locked->feedback],
            ]);
        });

        $submission->refresh();
    }
}
