<?php

namespace Tests\Support;

use App\Data\GitHubCommit;
use App\Data\RepoSnapshot;
use Carbon\CarbonImmutable;

/**
 * Builds RepoSnapshots by hand for check tests.
 */
class SnapshotBuilder
{
    /** @var list<string> */
    private array $paths = [];

    /** @var array<string, string> */
    private array $files = [];

    /** @var list<GitHubCommit> */
    private array $commits = [];

    public static function make(): self
    {
        return new self;
    }

    public function paths(string ...$paths): self
    {
        $this->paths = [...$this->paths, ...$paths];

        return $this;
    }

    public function file(string $path, string $content): self
    {
        $this->paths[] = $path;
        $this->files[$path] = $content;

        return $this;
    }

    public function commit(string $message, string $date = '2026-10-01 10:00:00', int $parents = 1): self
    {
        $this->commits[] = new GitHubCommit(sha1($message.count($this->commits)), CarbonImmutable::parse($date, 'UTC'), 'Student', $message, $parents);

        return $this;
    }

    public function commits(int $count, string $date = '2026-10-01 10:00:00'): self
    {
        foreach (range(1, $count) as $i) {
            $this->commit("feat: change {$i}", $date);
        }

        return $this;
    }

    public function build(): RepoSnapshot
    {
        return new RepoSnapshot('student', 'blog', str_repeat('a', 40), $this->paths, $this->files, [], $this->commits, 0, false);
    }
}
