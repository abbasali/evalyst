<?php

namespace App\Data;

/**
 * A repository at one commit, held in memory only while grading. File contents never
 * reach the database: `toManifest()` keeps paths, skip reasons and counts.
 */
final readonly class RepoSnapshot
{
    /**
     * @param  list<string>  $allPaths  Every blob path in the tree, unfiltered (for path checks).
     * @param  array<string, string>  $includedFiles  path => content, in priority order.
     * @param  array<string, string>  $skipped  path => reason.
     * @param  list<GitHubCommit>  $commits  Newest first.
     */
    public function __construct(
        public string $owner,
        public string $repo,
        public string $sha,
        public array $allPaths,
        public array $includedFiles,
        public array $skipped,
        public array $commits,
        public int $tokenEstimate,
        public bool $truncated,
    ) {}

    /**
     * @return list<GitHubCommit>
     */
    public function nonMergeCommits(): array
    {
        return array_values(array_filter($this->commits, fn (GitHubCommit $commit) => ! $commit->isMerge()));
    }

    /**
     * @return array{included: list<string>, skipped: array<string, string>, total_files: int, included_count: int, token_estimate: int, commit_count: int, truncated: bool}
     */
    public function toManifest(): array
    {
        return [
            'included' => array_keys($this->includedFiles),
            'skipped' => $this->skipped,
            'total_files' => count($this->allPaths),
            'included_count' => count($this->includedFiles),
            'token_estimate' => $this->tokenEstimate,
            'commit_count' => count($this->commits),
            'truncated' => $this->truncated,
        ];
    }
}
