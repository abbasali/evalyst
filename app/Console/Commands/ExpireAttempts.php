<?php

namespace App\Console\Commands;

use App\Actions\Attempts\ExpireOverdueAttempts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('attempts:expire')]
#[Description('Auto-submit quiz attempts whose time has run out (students who closed the tab)')]
class ExpireAttempts extends Command
{
    public function handle(ExpireOverdueAttempts $expire): int
    {
        $count = $expire->handle();

        $this->info("Auto-submitted {$count} attempt(s).");

        return self::SUCCESS;
    }
}
