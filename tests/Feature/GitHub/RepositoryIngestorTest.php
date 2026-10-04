<?php

use App\Models\Submission;
use App\Services\GitHub\RepositoryIngestor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('ignored paths are never downloaded but still listed for path checks', function () {
    fakeRepository();

    $snapshot = app(RepositoryIngestor::class)->ingest(Submission::factory()->create());

    expect($snapshot->allPaths)->toContain('vendor/autoload.php')
        ->and(array_keys($snapshot->includedFiles))->toBe([
            'README.md', 'composer.json',
            'app/Http/Controllers/PostController.php', 'app/Http/Requests/StorePostRequest.php', 'routes/web.php',
            'tests/Feature/PostTest.php',
            '.env.example',
        ])
        ->and($snapshot->skipped)->toMatchArray(['composer.lock' => 'ignored_pattern', 'public/logo.png' => 'binary', '.env' => 'ignored_pattern', 'database/big-seed.sql' => 'too_large'])
        ->and($snapshot->commits)->toHaveCount(13)
        ->and($snapshot->toManifest())->not->toHaveKey('files');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'raw.githubusercontent.com')
        && preg_match('#/(vendor|node_modules|storage)/|composer\.lock|logo\.png|/\.env$#', $request->url()));
});

test('files over the token budget are recorded, not fetched', function () {
    fakeRepository();
    config(['evalyst.github.max_context_tokens' => 400]);

    $snapshot = app(RepositoryIngestor::class)->ingest(Submission::factory()->create());

    expect(array_keys($snapshot->includedFiles))->toBe(['README.md', 'composer.json', 'routes/web.php', '.env.example'])
        ->and($snapshot->skipped['app/Http/Controllers/PostController.php'])->toBe('skipped_budget');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'PostController'));
});
