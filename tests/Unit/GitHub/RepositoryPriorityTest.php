<?php

use App\Services\GitHub\RepositoryIngestor;
use Tests\TestCase;

uses(TestCase::class);

test('files are prioritised the same way in any ecosystem', function (string $path, int $priority) {
    expect(RepositoryIngestor::priority($path))->toBe($priority);
})->with([
    ['README.md', 1],
    ['composer.json', 1],
    ['requirements.txt', 1],
    ['go.mod', 1],
    ['pom.xml', 1],
    ['Api.csproj', 1],
    ['app/Models/Post.php', 2],
    ['src/main/java/App.java', 2],
    ['cmd/server/main.go', 2],
    ['main.py', 2],
    ['templates/index.html', 3],
    ['resources/views/welcome.blade.php', 3],
    ['tests/test_models.py', 4],
    ['spec/user_spec.rb', 4],
    ['docs/notes.txt', 5],
]);
