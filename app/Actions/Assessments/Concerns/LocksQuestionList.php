<?php

namespace App\Actions\Assessments\Concerns;

use App\Models\Assessment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Serialise changes to an assessment's question list on the assessment row, and re-check
 * inside the lock that nobody has started (StartAttempt in M06 locks the same row).
 */
trait LocksQuestionList
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withQuestionListLock(Assessment $assessment, callable $callback): mixed
    {
        return DB::transaction(function () use ($assessment, $callback) {
            Assessment::query()->whereKey($assessment->id)->lockForUpdate()->first();

            if ($assessment->hasAttempts()) {
                throw new AuthorizationException(__('Locked because students have started.'));
            }

            return $callback();
        });
    }
}
