<?php

namespace App\Console\Commands;

use App\Actions\Attempts\SubmitAttempt;
use App\Enums\AttemptEventType;
use App\Enums\AttemptStatus;
use App\Models\Attempt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attempts:expire')]
#[Description('Auto-submit quiz attempts whose time has run out (students who closed the tab)')]
class ExpireAttempts extends Command
{
    public function handle(SubmitAttempt $submit): int
    {
        $count = 0;

        Attempt::query()
            ->where('status', AttemptStatus::InProgress)
            ->where('deadline_at', '<', now()->subSeconds((int) config('evalyst.quiz.save_grace_seconds')))
            ->chunkById(100, function ($attempts) use ($submit, &$count) {
                foreach ($attempts as $attempt) {
                    $count += (int) $submit->handle($attempt, AttemptEventType::AutoSubmitted);
                }
            });

        $this->info("Auto-submitted {$count} attempt(s).");

        return self::SUCCESS;
    }
}
