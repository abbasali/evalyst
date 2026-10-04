<?php

namespace App\Services\GitHub;

use App\Data\RepoSnapshot;
use App\Models\Submission;

/**
 * Builds a RepoSnapshot through the API at the submission's commit: tree first, filter,
 * prioritise, then stream files one at a time within the token budget (D-007).
 */
class RepositoryIngestor
{
    public function __construct(private GitHubClient $github) {}

    public function ingest(Submission $submission): RepoSnapshot
    {
        $owner = $submission->repo_owner;
        $repo = $submission->repo_name;
        $sha = $submission->commit_sha;
        $assessment = $submission->participant->assessment;

        $tree = $this->github->tree($owner, $repo, $sha);
        $allPaths = array_values(array_map(fn (array $entry) => $entry['path'], array_filter($tree['entries'], fn (array $entry) => $entry['type'] === 'blob')));

        $filtered = PathFilter::fromConfig()->filter($tree['entries'], $assessment->extra_ignored_paths ?? []);
        $skipped = $filtered['skipped'];
        $budget = (int) config('evalyst.github.max_context_tokens');
        $maxBytes = (int) config('evalyst.github.max_file_bytes');
        $tokens = 0;
        $files = [];

        foreach (self::prioritise($filtered['included']) as $entry) {
            $estimate = (int) ceil($entry['size'] / 4);

            if ($tokens + $estimate > $budget) {
                $skipped[$entry['path']] = 'skipped_budget';

                continue;
            }

            $content = $this->github->fileContent($owner, $repo, $sha, $entry['path'], $maxBytes);

            if ($content === null) {
                $skipped[$entry['path']] = 'too_large';
            } elseif (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
                $skipped[$entry['path']] = 'binary';
            } else {
                $files[$entry['path']] = $content;
                $tokens += $estimate;
            }
        }

        return new RepoSnapshot(
            owner: $owner,
            repo: $repo,
            sha: $sha,
            allPaths: $allPaths,
            includedFiles: $files,
            skipped: $skipped,
            commits: $this->github->commits($owner, $repo, $sha, (int) config('evalyst.github.max_commits')),
            tokenEstimate: $tokens,
            truncated: $tree['truncated'],
        );
    }

    /**
     * Order by priority group, then path.
     *
     * @param  list<array{path: string, size: int}>  $entries
     * @return list<array{path: string, size: int}>
     */
    public static function prioritise(array $entries): array
    {
        usort($entries, fn (array $a, array $b) => [self::priority($a['path']), $a['path']] <=> [self::priority($b['path']), $b['path']]);

        return $entries;
    }

    public static function priority(string $path): int
    {
        $groups = [
            1 => ['README*', 'readme*', 'composer.json', 'package.json'],
            2 => ['routes/**', 'app/**', 'database/migrations/**', 'config/*.php'],
            3 => ['resources/views/**', 'resources/js/**', 'resources/css/**'],
            4 => ['tests/**', 'database/seeders/**', 'database/factories/**'],
        ];

        foreach ($groups as $group => $globs) {
            foreach ($globs as $glob) {
                if (PathFilter::matches($glob, $path)) {
                    return $group;
                }
            }
        }

        return 5;
    }
}
