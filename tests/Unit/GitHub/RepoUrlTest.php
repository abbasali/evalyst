<?php

use App\Services\GitHub\RepoUrl;

dataset('valid urls', [
    ['https://github.com/student/blog', 'student', 'blog'],
    ['https://github.com/student/blog.git', 'student', 'blog'],
    ['https://github.com/student/blog/', 'student', 'blog'],
    ['http://www.github.com/Student-1/my_app.v2', 'Student-1', 'my_app.v2'],
    ['  https://github.com/a/b  ', 'a', 'b'],
]);

test('valid repository urls are normalised', function (string $url, string $owner, string $repo) {
    $parsed = RepoUrl::parse($url);

    expect($parsed?->owner)->toBe($owner)->and($parsed?->repo)->toBe($repo)
        ->and($parsed?->url())->toBe("https://github.com/{$owner}/{$repo}");
})->with('valid urls');

test('other urls are rejected', function (string $url) {
    expect(RepoUrl::parse($url))->toBeNull();
})->with([
    'gitlab' => 'https://gitlab.com/student/blog',
    'tree path' => 'https://github.com/student/blog/tree/main',
    'owner only' => 'https://github.com/student',
    'not a url' => 'student/blog',
    'ssh' => 'git@github.com:student/blog.git',
    'lookalike host' => 'https://github.com.evil.io/student/blog',
    'dots' => 'https://github.com/../blog',
]);
