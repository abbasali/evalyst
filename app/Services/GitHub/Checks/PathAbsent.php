<?php

namespace App\Services\GitHub\Checks;

use App\Data\RepoSnapshot;
use App\Models\Assessment;
use App\Services\GitHub\PathFilter;

/**
 * Nothing in the whole (unfiltered) tree matches `glob`, e.g. a committed vendor/ or .env.
 */
class PathAbsent implements Check
{
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult
    {
        $glob = (string) ($config['glob'] ?? '');
        $matches = array_values(array_filter($snapshot->allPaths, fn (string $path) => PathFilter::matches($glob, $path)));
        $passed = $matches === [];

        return new CheckResult(
            passed: $passed,
            score: $passed ? $marks : 0.0,
            reasoning: $passed
                ? __('Nothing matches :glob.', ['glob' => $glob])
                : __(':count committed file(s) match :glob.', ['count' => count($matches), 'glob' => $glob]),
            evidence: array_slice($matches, 0, 10),
        );
    }
}
