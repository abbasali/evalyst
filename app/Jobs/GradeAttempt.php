<?php

namespace App\Jobs;

use App\Actions\Grading\GradeAttempt as GradeAttemptAction;
use App\Models\Attempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Grades a submitted attempt (choice scoring now, open answers queued for AI).
 */
class GradeAttempt implements ShouldQueue
{
    use Queueable;

    /** An attempt reset by the instructor before grading ran is simply skipped. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Attempt $attempt) {}

    public function handle(GradeAttemptAction $grade): void
    {
        $grade->handle($this->attempt);
    }
}
