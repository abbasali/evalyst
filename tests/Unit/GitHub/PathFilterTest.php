<?php

use App\Services\GitHub\PathFilter;
use Tests\TestCase;

uses(TestCase::class);

dataset('paths', [
    ['app/Models/Post.php', 1000, null],
    ['vendor/autoload.php', 10, 'ignored_dir'],
    ['packages/x/node_modules/a.js', 10, 'ignored_dir'],
    ['bootstrap/cache/services.php', 10, 'ignored_dir'],
    ['bootstrap/app.php', 10, null],
    ['composer.lock', 10, 'ignored_pattern'],
    ['public/js/app.min.js', 10, 'ignored_pattern'],
    ['.env', 10, 'ignored_pattern'],
    ['.env.production', 10, 'ignored_pattern'],
    ['.env.example', 10, null],
    ['.github/workflows/tests.yml', 10, null],
    ['public/logo.PNG', 10, 'binary'],
    ['resources/svg/icon.svg', 10, 'binary'],
    ['database/seed.sql', 200_000, 'too_large'],
    ['docs/notes.md', 10, 'ignored_pattern', ['docs/**']],
    ['docs/notes.md', 10, 'ignored_pattern', ['docs/']],
    ['Vendor/x.php', 10, 'ignored_dir'],
    ['.ENV', 10, 'ignored_pattern'],
    ['deploy/id_rsa', 10, 'ignored_pattern'],
]);

test('paths are filtered before download', function (string $path, int $size, ?string $reason, array $extra = []) {
    expect(PathFilter::fromConfig()->reason($path, $size, $extra))->toBe($reason);
})->with('paths');

dataset('globs', [
    ['vendor/**', 'vendor/autoload.php', true],
    ['vendor/**', 'vendor', true],
    ['vendor/**', 'src/vendor/x.php', false],
    ['tests/Feature/*Test.php', 'tests/Feature/PostTest.php', true],
    ['tests/Feature/*Test.php', 'tests/Feature/Admin/PostTest.php', false],
    ['tests/**/*Test.php', 'tests/Feature/Admin/PostTest.php', true],
    ['**/*.php', 'routes/web.php', true],
    ['.env', '.env', true],
    ['.env', 'config/.env', false],
    ['docs/', 'docs/guide.md', true],
    ['docs', 'docs/guide.md', true],
]);

test('globs match like the rule builder documents', function (string $glob, string $path, bool $matches) {
    expect(PathFilter::matches($glob, $path))->toBe($matches);
})->with('globs');
