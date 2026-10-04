<?php

namespace App\Actions\Assessments;

use App\Actions\Audit\RecordAudit;
use App\Models\Assessment;
use App\Models\User;

class ReleaseResults
{
    public function __construct(private RecordAudit $audit) {}

    public function release(User $user, Assessment $assessment): void
    {
        if ($assessment->results_released_at !== null) {
            return;
        }

        $assessment->update(['results_released_at' => now()]);
        $this->audit->handle($user, $assessment, 'results.release');
    }

    public function unrelease(User $user, Assessment $assessment): void
    {
        if ($assessment->results_released_at === null) {
            return;
        }

        $before = $assessment->results_released_at->toIso8601String();
        $assessment->update(['results_released_at' => null]);
        $this->audit->handle($user, $assessment, 'results.unrelease', ['before' => ['results_released_at' => $before]]);
    }
}
