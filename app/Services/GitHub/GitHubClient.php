<?php

namespace App\Services\GitHub;

use App\Data\GitHubCommit;
use App\Data\GitHubRepository;
use App\Services\GitHub\Exceptions\GitHubRateLimited;
use App\Services\GitHub\Exceptions\GitHubUnavailable;
use App\Services\GitHub\Exceptions\RepositoryEmpty;
use App\Services\GitHub\Exceptions\RepositoryNotFound;
use App\Services\GitHub\Exceptions\RepositoryPrivate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads public repositories through the GitHub REST API only (D-007): never clones,
 * never downloads archives, never writes to disk. See docs/04-github-ingestion.md.
 */
class GitHubClient
{
    /**
     * @param  int  $timeout  Seconds per request.
     * @param  int  $tries  Attempts for connection errors and 5xx responses.
     */
    public function __construct(private int $timeout = 15, private int $tries = 3) {}

    /**
     * For calls made while a student waits (submitting): fail fast with a friendly message.
     */
    public static function quick(): self
    {
        return new self(timeout: 8, tries: 1);
    }

    public function repository(string $owner, string $repo): GitHubRepository
    {
        $data = $this->get("/repos/{$owner}/{$repo}")->json();

        if ((bool) ($data['private'] ?? false)) {
            throw new RepositoryPrivate("{$owner}/{$repo} is private.");
        }

        return new GitHubRepository(
            owner: (string) ($data['owner']['login'] ?? $owner),
            name: (string) ($data['name'] ?? $repo),
            private: false,
            defaultBranch: (string) ($data['default_branch'] ?? 'main'),
            sizeKb: (int) ($data['size'] ?? 0),
            archived: (bool) ($data['archived'] ?? false),
        );
    }

    public function headCommit(string $owner, string $repo, string $branch): GitHubCommit
    {
        return $this->commit($this->get("/repos/{$owner}/{$repo}/commits/".rawurlencode($branch))->json());
    }

    /**
     * The recursive tree at a commit.
     *
     * @return array{entries: list<array{path: string, type: string, size: int}>, truncated: bool}
     */
    public function tree(string $owner, string $repo, string $sha): array
    {
        $data = $this->get("/repos/{$owner}/{$repo}/git/trees/{$sha}", ['recursive' => 1])->json();

        $entries = array_values(array_map(fn (array $entry) => [
            'path' => (string) $entry['path'],
            'type' => (string) $entry['type'],
            'size' => (int) ($entry['size'] ?? 0),
        ], array_filter((array) ($data['tree'] ?? []), fn ($entry) => is_array($entry) && isset($entry['path'], $entry['type']))));

        return ['entries' => $entries, 'truncated' => (bool) ($data['truncated'] ?? false)];
    }

    /**
     * Commits reachable from `sha`, newest first, up to `max`.
     *
     * @return list<GitHubCommit>
     */
    public function commits(string $owner, string $repo, string $sha, int $max): array
    {
        $commits = [];
        $page = 1;

        while (count($commits) < $max) {
            $batch = (array) $this->get("/repos/{$owner}/{$repo}/commits", ['sha' => $sha, 'per_page' => 100, 'page' => $page])->json();

            foreach ($batch as $item) {
                if (is_array($item) && count($commits) < $max) {
                    $commits[] = $this->commit($item);
                }
            }

            if (count($batch) < 100) {
                break;
            }

            $page++;
        }

        return $commits;
    }

    /**
     * A file's raw content at a commit, read as a stream and cut off at `maxBytes`.
     * Returns null when the file is larger than the limit.
     */
    public function fileContent(string $owner, string $repo, string $sha, string $path, int $maxBytes): ?string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        try {
            $response = Http::withOptions(['stream' => true])
                ->timeout($this->timeout)
                ->get("https://raw.githubusercontent.com/{$owner}/{$repo}/{$sha}/{$encoded}");
        } catch (ConnectionException $exception) {
            throw new GitHubUnavailable($exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($this->isRateLimited($response)) {
            throw new GitHubRateLimited($this->resetsAt($response));
        }

        if ($response->failed()) {
            throw new GitHubUnavailable("Could not read {$path} ({$response->status()}).");
        }

        $body = $response->toPsrResponse()->getBody();
        $content = '';

        while (! $body->eof()) {
            $content .= $body->read(8192);

            if (strlen($content) > $maxBytes) {
                $body->close();

                return null;
            }
        }

        return $content;
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout($this->timeout)
            ->retry($this->tries, 500, fn ($exception) => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);

        if ($token = config('evalyst.github.token')) {
            $request->withToken((string) $token);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $uri, array $query = []): Response
    {
        try {
            $response = $this->client()->get($uri, $query);
        } catch (ConnectionException $exception) {
            throw new GitHubUnavailable($exception->getMessage(), previous: $exception);
        }

        if ($this->isRateLimited($response)) {
            throw new GitHubRateLimited($this->resetsAt($response));
        }

        if (in_array($response->status(), [404, 451], true)) {
            throw new RepositoryNotFound("Not found: {$uri}");
        }

        // GitHub answers 409 for a repository without any commits.
        if ($response->status() === 409) {
            throw new RepositoryEmpty("No commits: {$uri}");
        }

        if ($response->failed()) {
            throw new GitHubUnavailable("GitHub responded {$response->status()} for {$uri}.");
        }

        return $response;
    }

    private function isRateLimited(Response $response): bool
    {
        return $response->status() === 429
            || ($response->status() === 403 && ($response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== ''));
    }

    /**
     * Secondary limits send Retry-After; primary limits send the reset timestamp.
     */
    private function resetsAt(Response $response): CarbonImmutable
    {
        if (is_numeric($retryAfter = $response->header('Retry-After'))) {
            return CarbonImmutable::now()->addSeconds((int) $retryAfter);
        }

        return is_numeric($reset = $response->header('X-RateLimit-Reset'))
            ? CarbonImmutable::createFromTimestamp((int) $reset)
            : CarbonImmutable::now()->addMinute();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function commit(array $data): GitHubCommit
    {
        $date = $data['commit']['author']['date'] ?? $data['commit']['committer']['date'] ?? null;
        $message = (string) ($data['commit']['message'] ?? '');

        return new GitHubCommit(
            sha: (string) ($data['sha'] ?? ''),
            date: $date ? CarbonImmutable::parse((string) $date) : null,
            author: (string) ($data['commit']['author']['name'] ?? ''),
            message: mb_substr(strtok($message, "\n") ?: '', 0, 200),
            parents: count((array) ($data['parents'] ?? [])),
        );
    }
}
