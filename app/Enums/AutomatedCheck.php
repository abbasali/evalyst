<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AutomatedCheck: string
{
    use HasOptions;

    case MinCommits = 'min_commits';
    case MinCommitDays = 'min_commit_days';
    case CommitMessagePattern = 'commit_message_pattern';
    case PathExists = 'path_exists';
    case PathAbsent = 'path_absent';
    case FileContains = 'file_contains';

    public function label(): string
    {
        return match ($this) {
            self::MinCommits => 'Minimum commits',
            self::MinCommitDays => 'Commits on different days',
            self::CommitMessagePattern => 'Commit message format',
            self::PathExists => 'File or folder exists',
            self::PathAbsent => 'File or folder is absent',
            self::FileContains => 'File contains a pattern',
        };
    }
}
