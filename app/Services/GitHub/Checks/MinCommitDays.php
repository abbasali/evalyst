<?php

namespace App\Services\GitHub\Checks;

use App\Data\GitHubCommit;
use App\Data\RepoSnapshot;
use App\Models\Assessment;

/**
 * Commits on at least `min` distinct days (course timezone). Dates come from the student's
 * machine, so this is a hint, not proof.
 */
class MinCommitDays implements Check
{
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult
    {
        $min = max(1, (int) ($config['min'] ?? 1));
        $timezone = $assessment->team->timezone;

        $days = collect($snapshot->nonMergeCommits())
            ->filter(fn (GitHubCommit $commit) => $commit->date !== null)
            ->map(fn (GitHubCommit $commit) => $commit->date?->setTimezone($timezone)->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        return new CheckResult(
            passed: $days->count() >= $min,
            score: Partial::score($marks, $days->count() / $min),
            reasoning: __('Commits on :count of :min required days.', ['count' => $days->count(), 'min' => $min]),
            evidence: array_values($days->take(15)->all()),
        );
    }
}
