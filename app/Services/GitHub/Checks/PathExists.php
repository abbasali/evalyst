<?php

namespace App\Services\GitHub\Checks;

use App\Data\RepoSnapshot;
use App\Models\Assessment;
use App\Services\GitHub\PathFilter;

/**
 * At least `min_matches` paths in the whole (unfiltered) tree match `glob`. Pass/fail.
 */
class PathExists implements Check
{
    public function check(RepoSnapshot $snapshot, array $config, Assessment $assessment, float $marks): CheckResult
    {
        $glob = (string) ($config['glob'] ?? '');
        $min = max(1, (int) ($config['min_matches'] ?? 1));
        $matches = array_values(array_filter($snapshot->allPaths, fn (string $path) => PathFilter::matches($glob, $path)));
        $passed = count($matches) >= $min;

        return new CheckResult(
            passed: $passed,
            score: $passed ? $marks : 0.0,
            reasoning: __(':count file(s) match :glob (:min required).', ['count' => count($matches), 'glob' => $glob, 'min' => $min]),
            evidence: array_slice($matches, 0, 10),
        );
    }
}
