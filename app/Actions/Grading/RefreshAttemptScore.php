<?php

namespace App\Actions\Grading;

use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Models\Attempt;
use Illuminate\Support\Facades\DB;

class RefreshAttemptScore
{
    /**
     * Recalculate a submitted attempt's status and score from its answers. Idempotent.
     * The attempt is `graded` (score = sum) only when every answer is final; otherwise
     * it is `grading` with no score.
     */
    public function handle(Attempt $attempt): void
    {
        $updated = DB::transaction(function () use ($attempt) {
            $locked = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            // Gone (reset by the instructor) or not submitted yet.
            if ($locked === null || $locked->isInProgress()) {
                return $locked !== null;
            }

            $answers = $locked->answers()->get(['id', 'score', 'grading_status']);
            $allFinal = $answers->every(fn ($answer) => $answer->grading_status === AnswerGradingStatus::Final);

            $locked->update([
                'status' => $allFinal ? AttemptStatus::Graded : AttemptStatus::Grading,
                'score' => $allFinal ? round($answers->sum(fn ($answer) => (float) $answer->score), 2) : null,
            ]);

            return true;
        });

        if ($updated) {
            $attempt->refresh();
        }
    }
}
