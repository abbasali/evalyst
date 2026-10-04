<?php

namespace App\Jobs;

use App\Models\Attempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Grades a submitted attempt. A no-op until M07.2 adds choice scoring and AI grading.
 */
class GradeAttempt implements ShouldQueue
{
    use Queueable;

    /** An attempt reset by the instructor before grading ran is simply skipped. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Attempt $attempt) {}

    public function handle(): void
    {
        //
    }
}
