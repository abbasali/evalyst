<?php

use App\Services\GitHub\Exceptions\GitHubRateLimited;
use App\Services\GitHub\Exceptions\RepositoryEmpty;
use App\Services\GitHub\Exceptions\RepositoryNotFound;
use App\Services\GitHub\Exceptions\RepositoryPrivate;
use App\Services\GitHub\GitHubClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::preventStrayRequests());

function githubFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json")), true);
}

test('it reads a public repository and its head commit', function () {
    config(['evalyst.github.token' => 'secret-token']);
    Http::fake([
        'api.github.com/repos/student/blog' => Http::response(githubFixture('repository-public')),
        'api.github.com/repos/student/blog/commits/main' => Http::response(githubFixture('commit-head')),
    ]);

    $client = new GitHubClient;
    $repo = $client->repository('student', 'blog');
    $head = $client->headCommit('student', 'blog', $repo->defaultBranch);

    expect($repo->defaultBranch)->toBe('main')
        ->and($head->sha)->toBe('4f2c1a9e8b7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b')
        ->and($head->message)->toBe('feat: add posts CRUD');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('no token header is sent without a token', function () {
    config(['evalyst.github.token' => null]);
    Http::fake(['api.github.com/*' => Http::response(githubFixture('repository-public'))]);

    (new GitHubClient)->repository('student', 'blog');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

test('missing, private and rate-limited repositories throw', function (int $status, ?string $fixture, array $headers, string $exception) {
    Http::fake(['api.github.com/*' => Http::response($fixture ? githubFixture($fixture) : ['message' => 'x'], $status, $headers)]);

    expect(fn () => (new GitHubClient)->repository('student', 'blog'))->toThrow($exception);
})->with([
    'not found' => [404, null, [], RepositoryNotFound::class],
    'private' => [200, 'repository-private', [], RepositoryPrivate::class],
    'rate limited' => [403, null, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1999999999'], GitHubRateLimited::class],
]);

test('an empty repository and secondary rate limits are told apart', function () {
    Http::fake([
        'api.github.com/repos/student/empty/commits/main' => Http::response(['message' => 'Git Repository is empty.'], 409),
        'api.github.com/repos/student/busy/commits/main' => Http::response(['message' => 'secondary'], 429, ['Retry-After' => '30']),
    ]);

    expect(fn () => (new GitHubClient)->headCommit('student', 'empty', 'main'))->toThrow(RepositoryEmpty::class);

    try {
        (new GitHubClient)->headCommit('student', 'busy', 'main');
    } catch (GitHubRateLimited $exception) {
        expect($exception->resetsAt->diffInSeconds(now(), true))->toBeLessThanOrEqual(31);
    }
});
