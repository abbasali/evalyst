<?php

namespace App\Actions\Attempts;

use App\Enums\AttemptEventType;
use App\Models\Assessment;
use App\Models\Attempt;

class ExpireOverdueAttempts
{
    public function __construct(private SubmitAttempt $submit) {}

    /**
     * Auto-submit every overdue attempt (students who closed the tab), optionally for one
     * assessment only. Returns how many were submitted.
     */
    public function handle(?Assessment $assessment = null): int
    {
        $count = 0;

        Attempt::query()
            ->overdue()
            ->when($assessment, fn ($query) => $query->whereHas('participant', fn ($query) => $query->where('assessment_id', $assessment->id)))
            ->chunkById(100, function ($attempts) use (&$count) {
                foreach ($attempts as $attempt) {
                    $count += (int) $this->submit->handle($attempt, AttemptEventType::AutoSubmitted);
                }
            });

        return $count;
    }
}
