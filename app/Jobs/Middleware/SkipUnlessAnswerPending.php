<?php

namespace App\Jobs\Middleware;

use App\Enums\AnswerGradingStatus;
use App\Jobs\GradeOpenAnswer;
use App\Models\Answer;
use Closure;

/**
 * Runs before the overlap lock and rate limiter, so duplicate or stale grading jobs
 * (e.g. re-dispatched by grading:recover) are dropped without using a rate-limit slot.
 */
class SkipUnlessAnswerPending
{
    public function handle(GradeOpenAnswer $job, Closure $next): mixed
    {
        $pending = Answer::query()
            ->whereKey($job->answerId)
            ->where('grading_status', AnswerGradingStatus::Pending)
            ->exists();

        return $pending ? $next($job) : null;
    }
}
