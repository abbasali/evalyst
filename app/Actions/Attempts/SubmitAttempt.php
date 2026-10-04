<?php

namespace App\Actions\Attempts;

use App\Enums\AttemptEventType;
use App\Enums\AttemptStatus;
use App\Jobs\GradeAttempt;
use App\Models\Attempt;
use Illuminate\Support\Facades\DB;

class SubmitAttempt
{
    /**
     * Submit an in-progress attempt and queue grading. Idempotent: returns false if it was
     * already submitted.
     *
     * @param  AttemptEventType|null  $event  `auto_submitted` (timer/expiry) or `force_submitted` (instructor)
     */
    public function handle(Attempt $attempt, ?AttemptEventType $event = null): bool
    {
        $submitted = DB::transaction(function () use ($attempt, $event) {
            $fresh = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if (! $fresh?->isInProgress()) {
                return false;
            }

            $fresh->update([
                'status' => AttemptStatus::Submitted,
                'submitted_at' => now(),
                'auto_submitted' => $event !== null,
            ]);

            if ($event !== null) {
                $fresh->events()->create(['type' => $event, 'occurred_at' => now()]);
            }

            return true;
        });

        $attempt->refresh();

        if ($submitted) {
            GradeAttempt::dispatch($attempt);
        }

        return $submitted;
    }

    /**
     * Auto-submit an attempt whose time (plus the save grace) has run out.
     */
    public function expireIfOverdue(Attempt $attempt): bool
    {
        return $attempt->isInProgress() && $attempt->isOverdue()
            && $this->handle($attempt, AttemptEventType::AutoSubmitted);
    }
}
