<?php

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Tag;

function choicePayload(array $overrides = []): array
{
    return [
        'type' => 'single_choice',
        'body' => 'What does `echo 1 <=> 2;` print?',
        'default_marks' => 1,
        'options' => [
            ['body' => '-1', 'is_correct' => true],
            ['body' => '0', 'is_correct' => false],
            ['body' => '1', 'is_correct' => false],
        ],
        ...$overrides,
    ];
}

test('questions of every type can be created', function (array $payload) {
    [, $team] = actingAsInstructor();

    $this->post(route('questions.store', $team), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('questions.index', $team));

    $question = $team->questions()->sole();
    expect($question->type->value)->toBe($payload['type'])
        ->and($question->options()->count())->toBe(count($payload['options'] ?? []));
})->with([
    'single choice' => [choicePayload()],
    'multiple choice' => [choicePayload([
        'type' => 'multiple_choice',
        'scoring_policy' => 'partial',
        'options' => [
            ['body' => 'array_map', 'is_correct' => true],
            ['body' => 'array_filter', 'is_correct' => true],
            ['body' => 'strlen', 'is_correct' => false],
        ],
    ])],
    'open text' => [['type' => 'open_text', 'body' => 'Explain closures.', 'default_marks' => 2, 'model_answer' => 'A function capturing scope.']],
    'open code' => [['type' => 'open_code', 'body' => 'Write a route.', 'default_marks' => 3, 'model_answer' => 'Route::get(...)', 'code_language' => 'php']],
]);

test('question validation rules depend on the type', function (array $payload, string $errorKey) {
    [, $team] = actingAsInstructor();

    $this->post(route('questions.store', $team), $payload)->assertSessionHasErrors($errorKey);
})->with([
    'single choice needs exactly one correct' => [choicePayload(['options' => [['body' => 'a', 'is_correct' => true], ['body' => 'b', 'is_correct' => true]]]), 'options'],
    'single choice needs two options' => [choicePayload(['options' => [['body' => 'a', 'is_correct' => true]]]), 'options'],
    'multiple choice needs two correct' => [choicePayload(['type' => 'multiple_choice', 'scoring_policy' => 'partial']), 'options'],
    'multiple choice needs one incorrect' => [choicePayload(['type' => 'multiple_choice', 'scoring_policy' => 'partial', 'options' => [['body' => 'a', 'is_correct' => true], ['body' => 'b', 'is_correct' => true], ['body' => 'c', 'is_correct' => true]]]), 'options'],
    'options must be distinct' => [choicePayload(['options' => [['body' => 'Same', 'is_correct' => true], ['body' => 'same', 'is_correct' => false]]]), 'options.1.body'],
    'open questions need a model answer' => [['type' => 'open_text', 'body' => 'x', 'default_marks' => 1], 'model_answer'],
    'code questions need a language' => [['type' => 'open_code', 'body' => 'x', 'default_marks' => 1, 'model_answer' => 'y'], 'code_language'],
    'marks in half steps' => [choicePayload(['default_marks' => 1.3]), 'default_marks'],
]);

test('updating keeps option ids and syncs by position', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->for($team)->create();
    $ids = $question->options()->pluck('id')->all();

    $this->put(route('questions.update', [$team, $question]), choicePayload())->assertSessionHasNoErrors();

    expect($question->options()->pluck('id')->all())->toBe(array_slice($ids, 0, 3))
        ->and($question->options()->pluck('body')->all())->toBe(['-1', '0', '1']);
});

test('locked questions keep student-facing fields but grading fields stay editable', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->for($team)->openText()->locked()->create(['body' => 'Original']);

    $this->put(route('questions.update', [$team, $question]), [
        'type' => 'single_choice',
        'body' => 'Changed',
        'default_marks' => 4,
        'model_answer' => 'Better answer',
        'rubric' => 'New rubric',
    ])->assertSessionHasNoErrors();

    $question->refresh();
    expect($question->body)->toBe('Original')
        ->and($question->type)->toBe(QuestionType::OpenText)
        ->and($question->rubric)->toBe('New rubric')
        ->and((float) $question->default_marks)->toBe(4.0);
});

test('a question can be duplicated as an unlocked copy', function () {
    [, $team] = actingAsInstructor();
    $tag = Tag::factory()->for($team)->create();
    $question = Question::factory()->for($team)->aiGenerated()->locked()->create();
    $question->tags()->attach($tag);

    $response = $this->post(route('questions.duplicate', [$team, $question]));

    $copy = $team->questions()->whereKeyNot($question->id)->sole();
    $response->assertRedirect(route('questions.edit', [$team, $copy]));
    expect($copy->isLocked())->toBeFalse()
        ->and($copy->source->value)->toBe('manual')
        ->and($copy->options()->count())->toBe(4)
        ->and($copy->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

test('the index filters questions and never shows other courses', function () {
    [, $team] = actingAsInstructor();
    $tag = Tag::factory()->for($team)->create();
    Question::factory()->for($team)->openText()->create(['body' => 'Explain middleware']);
    Question::factory()->for($team)->create(['body' => 'Pick a verb'])->tags()->attach($tag);
    Question::factory()->create(['body' => 'Explain middleware elsewhere']);

    $this->get(route('questions.index', [$team, 'q' => 'middleware']))
        ->assertInertia(fn ($page) => $page->has('questions.data', 1)->where('questions.data.0.type', 'open_text'));

    $this->get(route('questions.index', [$team, 'tags' => [$tag->id], 'type' => 'single_choice']))
        ->assertInertia(fn ($page) => $page->has('questions.data', 1)->where('questions.data.0.tags.0.id', $tag->id));
});

test('questions from another course are not reachable', function () {
    [, $team] = actingAsInstructor();
    $foreign = Question::factory()->create();

    $this->get(route('questions.edit', [$team, $foreign]))->assertNotFound();
    $this->get(route('questions.show', [$team, $foreign]))->assertNotFound();
    $this->post(route('questions.duplicate', [$team, $foreign]))->assertNotFound();
});

test('deleted questions can be restored', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->for($team)->create();

    $this->delete(route('questions.destroy', [$team, $question]));
    expect($question->fresh()->trashed())->toBeTrue();

    $this->post(route('questions.restore', [$team, $question->id]));
    expect($question->fresh()->trashed())->toBeFalse();
});

test('tags are unique per course and bulk tagging only accepts course tags', function () {
    [, $team] = actingAsInstructor();
    $questions = Question::factory()->for($team)->count(2)->create();

    $first = $this->postJson(route('tags.store', $team), ['name' => 'Arrays'])->assertCreated()->json('id');
    $again = $this->postJson(route('tags.store', $team), ['name' => ' arrays '])->assertOk()->json('id');
    expect($again)->toBe($first);

    $this->post(route('questions.bulk', $team), ['action' => 'add_tag', 'ids' => $questions->modelKeys(), 'tag_id' => $first])
        ->assertSessionHasNoErrors();
    expect(Tag::find($first)->questions()->count())->toBe(2);

    $foreignTag = Tag::factory()->create();
    $this->post(route('questions.bulk', $team), ['action' => 'add_tag', 'ids' => $questions->modelKeys(), 'tag_id' => $foreignTag->id])
        ->assertSessionHasErrors('tag_id');
});

test('grading fields of a locked question save even if its frozen content breaks current rules', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->for($team)->multipleChoice()->locked()->create();
    $question->options()->update(['is_correct' => true]); // every option correct: invalid today

    $this->put(route('questions.update', [$team, $question]), ['default_marks' => 3, 'scoring_policy' => 'partial', 'rubric' => 'Updated'])
        ->assertSessionHasNoErrors();

    expect($question->fresh()->rubric)->toBe('Updated');
});

test('deleted questions can be previewed', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->for($team)->create();
    $question->delete();

    $this->getJson(route('questions.show', [$team, $question]))->assertOk()->assertJsonPath('deleted', true);
});
