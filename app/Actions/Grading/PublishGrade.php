<?php

namespace App\Actions\Grading;

use App\Actions\Audit\RecordAudit;
use App\Enums\AnswerGradingStatus;
use App\Models\Answer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An instructor settles an answer's grade: accepting the AI suggestion or setting their own.
 * Each call writes one audit log with the before/after score and feedback.
 */
class PublishGrade
{
    public function __construct(private RecordAudit $audit, private RefreshAttemptScore $refresh) {}

    /**
     * Publish the AI's suggested score and feedback.
     *
     * @param  string  $action  `grade.accept` or `grade.bulk_accept`
     */
    public function accept(User $user, Answer $answer, string $action = 'grade.accept'): bool
    {
        return $this->publish($user, $answer, $action, null, null);
    }

    /**
     * Publish the instructor's own score and feedback (also for already-final answers).
     */
    public function override(User $user, Answer $answer, float $score, ?string $feedback, ?string $note = null): bool
    {
        return $this->publish($user, $answer, 'grade.override', $score, $feedback, $note);
    }

    private function publish(User $user, Answer $answer, string $action, ?float $score, ?string $feedback, ?string $note = null): bool
    {
        $published = DB::transaction(function () use ($user, $answer, $action, $score, $feedback, $note) {
            $locked = Answer::query()->whereKey($answer->id)->lockForUpdate()->firstOrFail();

            if ($locked->attempt()->firstOrFail()->isInProgress()) {
                throw ValidationException::withMessages(['score' => __('The student is still taking the quiz.'), 'decision' => __('The student is still taking the quiz.')]);
            }

            $accepting = $score === null;

            if ($accepting && ($locked->ai_score === null || $locked->grading_status !== AnswerGradingStatus::NeedsReview)) {
                return false;
            }

            $after = [
                'score' => $accepting ? (float) $locked->ai_score : $score,
                'feedback' => $accepting ? $locked->ai_feedback : $feedback,
            ];
            $before = [
                'score' => $locked->score !== null ? (float) $locked->score : null,
                'feedback' => $locked->feedback,
                'status' => $locked->grading_status->value,
            ];

            $locked->update([
                ...$after,
                'grading_status' => AnswerGradingStatus::Final,
                'graded_by' => $user->id,
                'review_reasons' => null,
                'published_at' => now(),
            ]);

            $this->audit->handle($user, $locked, $action, ['before' => $before, 'after' => $after], $note);

            return true;
        });

        if ($published) {
            $this->refresh->handle($answer->attempt);
        }

        $answer->refresh();

        return $published;
    }
}
