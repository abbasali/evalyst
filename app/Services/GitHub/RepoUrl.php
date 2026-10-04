<?php

namespace App\Services\GitHub;

/**
 * A normalised public GitHub repository URL: https://github.com/{owner}/{repo}.
 */
final readonly class RepoUrl
{
    private const PATTERN = '#^https?://(?:www\.)?github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#i';

    private function __construct(public string $owner, public string $repo) {}

    /**
     * Null when the URL isn't a plain repository URL (other hosts, /tree/..., extra path).
     */
    public static function parse(string $url): ?self
    {
        if (preg_match(self::PATTERN, trim($url), $match) !== 1) {
            return null;
        }

        [, $owner, $repo] = $match;

        if (in_array($repo, ['.', '..'], true) || in_array($owner, ['.', '..'], true)) {
            return null;
        }

        return new self($owner, $repo);
    }

    public function url(): string
    {
        return "https://github.com/{$this->owner}/{$this->repo}";
    }

    public static function commitUrl(string $owner, string $repo, string $sha): string
    {
        return "https://github.com/{$owner}/{$repo}/tree/{$sha}";
    }
}
