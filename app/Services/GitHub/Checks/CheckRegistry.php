<?php

namespace App\Services\GitHub\Checks;

use App\Enums\AutomatedCheck;

class CheckRegistry
{
    public function for(AutomatedCheck $check): Check
    {
        return app(match ($check) {
            AutomatedCheck::MinCommits => MinCommits::class,
            AutomatedCheck::MinCommitDays => MinCommitDays::class,
            AutomatedCheck::CommitMessagePattern => CommitMessagePattern::class,
            AutomatedCheck::PathExists => PathExists::class,
            AutomatedCheck::PathAbsent => PathAbsent::class,
            AutomatedCheck::FileContains => FileContains::class,
        });
    }
}
