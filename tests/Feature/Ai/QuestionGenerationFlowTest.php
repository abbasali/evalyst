<?php

use App\Enums\GenerationStatus;
use App\Jobs\GenerateQuestions;
use App\Models\Question;
use App\Models\QuestionGeneration;
use App\Models\Tag;
use Illuminate\Support\Facades\Queue;

function generationPayload(array $overrides = []): array
{
    return [
        'prompt' => 'PHP arrays and sorting functions',
        'type_counts' => ['single_choice' => 3, 'multiple_choice' => 0, 'open_text' => 1, 'open_code' => 0],
        'difficulty' => 'mixed',
        'include_code_output' => true,
        'tag_ids' => [],
        ...$overrides,
    ];
}

function completedGeneration($team, array $verification = ['status' => 'agreed']): QuestionGeneration
{
    return $team->questionGenerations()->create([
        'prompt' => 'Arrays', 'type_counts' => ['single_choice' => 1], 'difficulty' => 'easy',
        'include_code_output' => false, 'status' => GenerationStatus::Completed,
        'tag_ids' => [Tag::factory()->for($team)->create()->id],
        'drafts' => [[
            'uid' => 'draft-1', 'type' => 'single_choice', 'body' => 'What does sort() return?', 'code_language' => null,
            'options' => [['body' => 'true', 'is_correct' => true], ['body' => 'The sorted array', 'is_correct' => false]],
            'model_answer' => null, 'rubric' => null, 'explanation' => 'It sorts in place.', 'difficulty' => 'easy',
            'suggested_marks' => 1, 'is_code_output' => false, 'verification' => $verification, 'accepted' => false,
        ]],
    ]);
}

function acceptedPayload(array $overrides = []): array
{
    return ['drafts' => [[
        'uid' => 'draft-1', 'type' => 'single_choice', 'body' => 'What does sort() return?', 'default_marks' => 1,
        'options' => [['body' => 'true', 'is_correct' => true], ['body' => 'The sorted array', 'is_correct' => false]],
        'explanation' => 'It sorts in place.', 'difficulty' => 'easy',
        ...$overrides,
    ]]];
}

test('a generation is queued and the instructor is sent to its page', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();

    $response = $this->post(route('question-generations.store', $team), generationPayload());

    $generation = $team->questionGenerations()->sole();
    $response->assertRedirect(route('question-generations.show', [$team, $generation]));
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->requestedTotal())->toBe(4);
    Queue::assertPushedOn('ai', GenerateQuestions::class);
});

test('generation requests are validated', function (array $overrides, string $error) {
    Queue::fake();
    [, $team] = actingAsInstructor();

    $this->post(route('question-generations.store', $team), generationPayload($overrides))->assertSessionHasErrors($error);
    Queue::assertNothingPushed();
})->with([
    'short prompt' => [['prompt' => 'PHP'], 'prompt'],
    'too many of one type' => [['type_counts' => ['single_choice' => 16, 'multiple_choice' => 0, 'open_text' => 0, 'open_code' => 0]], 'type_counts.single_choice'],
    'nothing requested' => [['type_counts' => ['single_choice' => 0, 'multiple_choice' => 0, 'open_text' => 0, 'open_code' => 0]], 'type_counts'],
    'over the total' => [['type_counts' => ['single_choice' => 15, 'multiple_choice' => 15, 'open_text' => 1, 'open_code' => 0]], 'type_counts'],
    'foreign tag' => [['tag_ids' => [999999]], 'tag_ids.0'],
]);

test('only three generations can run at once per course', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    foreach (range(1, 3) as $i) {
        $team->questionGenerations()->create(['prompt' => 'x', 'type_counts' => ['open_text' => 1], 'difficulty' => 'easy', 'status' => GenerationStatus::Running]);
    }

    $this->post(route('question-generations.store', $team), generationPayload())->assertSessionHasErrors('prompt');
});

test('accepted drafts become AI questions with the generation tags', function () {
    [, $team] = actingAsInstructor();
    $generation = completedGeneration($team);

    $this->post(route('question-generations.accept', [$team, $generation]), acceptedPayload(['default_marks' => 2]))
        ->assertSessionHasNoErrors();

    $question = $team->questions()->sole();
    expect($question->source->value)->toBe('ai')
        ->and($question->question_generation_id)->toBe($generation->id)
        ->and((float) $question->default_marks)->toBe(2.0)
        ->and($question->needs_verification)->toBeFalse()
        ->and($question->tags()->pluck('tags.id')->all())->toBe($generation->tag_ids)
        ->and($generation->fresh()->accepted_count)->toBe(1)
        ->and($generation->fresh()->drafts[0]['accepted'])->toBeTrue();

    // A draft can't be added twice.
    $this->post(route('question-generations.accept', [$team, $generation]), acceptedPayload())->assertSessionHasErrors('drafts');
    expect($team->questions()->count())->toBe(1);
});

test('disputed drafts need verification unless the instructor edited the key', function (array $overrides, bool $needsVerification) {
    [, $team] = actingAsInstructor();
    $generation = completedGeneration($team, ['status' => 'disputed', 'verifier_selected' => [1]]);

    $this->post(route('question-generations.accept', [$team, $generation]), acceptedPayload($overrides))->assertSessionHasNoErrors();

    expect($team->questions()->sole()->needs_verification)->toBe($needsVerification);
})->with([
    'unchanged' => [[], true],
    'key fixed' => [['options' => [['body' => 'true', 'is_correct' => false], ['body' => 'The sorted array', 'is_correct' => true]]], false],
]);

test('invalid edited drafts are rejected', function () {
    [, $team] = actingAsInstructor();
    $generation = completedGeneration($team);

    $this->post(route('question-generations.accept', [$team, $generation]), acceptedPayload([
        'options' => [['body' => 'a', 'is_correct' => true], ['body' => 'b', 'is_correct' => true]],
    ]))->assertSessionHasErrors('drafts.0.options');

    expect($team->questions()->count())->toBe(0);
});

test('only failed generations can be retried', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    $generation = completedGeneration($team);

    $this->post(route('question-generations.retry', [$team, $generation]))->assertStatus(422);

    $generation->update(['status' => GenerationStatus::Failed]);
    $this->post(route('question-generations.retry', [$team, $generation]))->assertRedirect();
    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
    Queue::assertPushed(GenerateQuestions::class);
});

test('generations of another course are not reachable', function () {
    [, $team] = actingAsInstructor();
    $other = Question::factory()->create()->team;
    $generation = completedGeneration($other);

    $this->get(route('question-generations.show', [$team, $generation]))->assertNotFound();
    $this->post(route('question-generations.accept', [$team, $generation]), acceptedPayload())->assertNotFound();
});

test('a question can be marked verified', function () {
    [, $team] = actingAsInstructor();
    $question = Question::factory()->for($team)->aiGenerated()->create(['needs_verification' => true]);

    $this->patch(route('questions.verify', [$team, $question]))->assertRedirect();
    expect($question->fresh()->needs_verification)->toBeFalse();
});

test('accepting still works after a generation tag was deleted', function () {
    [, $team] = actingAsInstructor();
    $generation = completedGeneration($team);
    Tag::whereKey($generation->tag_ids)->delete();

    $this->post(route('question-generations.accept', [$team, $generation]), acceptedPayload())->assertSessionHasNoErrors();

    expect($team->questions()->sole()->tags()->count())->toBe(0);
});

test('stuck generations can be retried and do not block new ones', function () {
    Queue::fake();
    [, $team] = actingAsInstructor();
    $stuck = collect(range(1, 3))->map(fn () => $team->questionGenerations()->create([
        'prompt' => 'x', 'type_counts' => ['open_text' => 1], 'difficulty' => 'easy', 'status' => GenerationStatus::Running,
    ]));
    QuestionGeneration::query()->update(['updated_at' => now()->subHour()]);

    $this->post(route('question-generations.store', $team), generationPayload())->assertSessionHasNoErrors();
    $this->post(route('question-generations.retry', [$team, $stuck->first()]))->assertRedirect();
});

test('a running generation with checkpointed drafts can be viewed', function () {
    [, $team] = actingAsInstructor();
    $generation = completedGeneration($team);
    $generation->update(['status' => GenerationStatus::Running, 'drafts' => [['type' => 'single_choice', 'body' => 'Unverified']]]);

    $this->get(route('question-generations.show', [$team, $generation]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('generation.status', 'running')->has('drafts', 0));

    $this->get(route('question-generations.create', $team))->assertOk();
});
