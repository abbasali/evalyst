<?php

use App\Enums\AccessMode;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;

function quizPayload(array $overrides = []): array
{
    return [
        'title' => 'Week 3: Eloquent',
        'instructions' => 'Read **carefully**.',
        'opens_at' => '2026-10-10T09:30',
        'closes_at' => '2026-10-10T11:00',
        'duration_minutes' => 45,
        'shuffle_questions' => true,
        'shuffle_options' => false,
        'show_answers_after_release' => true,
        'track_focus' => true,
        'release_mode' => 'manual',
        'auto_publish_threshold' => null,
        'access_mode' => 'roster',
        ...$overrides,
    ];
}

it('creates a quiz with times entered in the course timezone and stored in UTC', function () {
    [, $team] = actingAsInstructor();
    $team->update(['timezone' => 'Asia/Kolkata']);

    $response = $this->post(route('quizzes.store', $team), quizPayload());

    $quiz = $team->quizzes()->sole();
    $response->assertRedirect(route('quizzes.questions.index', [$team, $quiz]));

    expect($quiz->opens_at->toDateTimeString())->toBe('2026-10-10 04:00:00')
        ->and($quiz->closes_at->toDateTimeString())->toBe('2026-10-10 05:30:00')
        ->and($quiz->isDraft())->toBeTrue()
        ->and($quiz->shuffle_questions)->toBeTrue();

    $this->get(route('quizzes.edit', [$team, $quiz]))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/Settings')
            ->where('form.opens_at', '2026-10-10T09:30')
            ->where('form.closes_at', '2026-10-10T11:00'));
});

dataset('invalid settings', [
    'missing title' => [['title' => ''], 'title'],
    'title too long' => [['title' => str_repeat('a', 151)], 'title'],
    'missing closes_at' => [['closes_at' => ''], 'closes_at'],
    'opens after closes' => [['opens_at' => '2026-10-10T12:00'], 'opens_at'],
    'duration zero' => [['duration_minutes' => 0], 'duration_minutes'],
    'duration too long' => [['duration_minutes' => 601], 'duration_minutes'],
    'threshold too low' => [['auto_publish_threshold' => 0.4], 'auto_publish_threshold'],
    'threshold too high' => [['auto_publish_threshold' => 1.2], 'auto_publish_threshold'],
    'bad release mode' => [['release_mode' => 'later'], 'release_mode'],
    'bad access mode' => [['access_mode' => 'open'], 'access_mode'],
]);

it('validates quiz settings', function (array $overrides, string $field) {
    [, $team] = actingAsInstructor();

    $this->post(route('quizzes.store', $team), quizPayload($overrides))->assertSessionHasErrors($field);
})->with('invalid settings');

it('generates a shared code when shared-code mode is chosen', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->create();

    $this->put(route('quizzes.update', [$team, $quiz]), quizPayload(['access_mode' => 'shared_code']))->assertSessionHasNoErrors();

    expect($quiz->fresh()->shared_code)->toMatch('/^[A-Z2-9]{6}$/');
});

it('blocks switching access mode once participants exist', function () {
    [, $team] = actingAsInstructor();
    $quiz = Assessment::factory()->for($team)->rosterMode()->create();
    Participant::factory()->for($quiz)->withCode()->create();

    $this->put(route('quizzes.update', [$team, $quiz]), quizPayload(['access_mode' => 'shared_code']))
        ->assertSessionHasErrors('access_mode');

    expect($quiz->fresh()->access_mode)->toBe(AccessMode::Roster);
});

it('locks score-changing settings once a student has started', function () {
    [, $team] = actingAsInstructor();
    $team->update(['timezone' => 'UTC']);
    $quiz = Assessment::factory()->for($team)->open()->create([
        'opens_at' => '2026-10-10 09:00:00', 'closes_at' => '2026-10-10 11:00:00', 'duration_minutes' => 45,
    ]);
    Attempt::factory()->for(Participant::factory()->for($quiz)->withCode())->create();
    $this->travelTo('2026-10-10 10:00:00');

    $allowed = quizPayload([
        'title' => 'Renamed', 'opens_at' => '2026-10-10T09:00', 'closes_at' => '2026-10-10T12:00',
        'shuffle_questions' => false, 'release_mode' => 'automatic', 'auto_publish_threshold' => 0.9,
    ]);
    $this->put(route('quizzes.update', [$team, $quiz]), $allowed)->assertSessionHasNoErrors();
    expect($quiz->fresh()->title)->toBe('Renamed');

    foreach ([
        'duration_minutes' => 60,
        'shuffle_questions' => true,
        'shuffle_options' => true,
        'track_focus' => false,
        'opens_at' => '2026-10-10T09:15',
        'closes_at' => '2026-10-10T11:30',
        'access_mode' => 'shared_code',
    ] as $field => $value) {
        $this->put(route('quizzes.update', [$team, $quiz]), [...$allowed, $field => $value])->assertSessionHasErrors($field);
    }
});

it('lists quizzes by tab', function () {
    [, $team] = actingAsInstructor();
    Assessment::factory()->for($team)->create(['title' => 'Draft one']);
    Assessment::factory()->for($team)->open()->create(['title' => 'Open one']);
    Assessment::factory()->for($team)->closed()->create();
    Assessment::factory()->for($team)->assignment()->open()->create();

    $this->get(route('quizzes.index', $team))
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/Index')
            ->where('tab', 'open')
            ->has('quizzes.data', 1)
            ->where('quizzes.data.0.title', 'Open one')
            ->where('counts', ['open' => 1, 'upcoming' => 0, 'draft' => 1, 'closed' => 1, 'archived' => 0]));
});

it('only deletes drafts without participants', function () {
    [, $team] = actingAsInstructor();
    $draft = Assessment::factory()->for($team)->create();
    $published = Assessment::factory()->for($team)->open()->create();

    $this->delete(route('quizzes.destroy', [$team, $published]))->assertForbidden();
    $this->delete(route('quizzes.destroy', [$team, $draft]))->assertRedirect(route('quizzes.index', $team));

    expect($draft->fresh()->trashed())->toBeTrue();
});

it('keeps quizzes inside their course', function (string $method, string $route) {
    [, $team] = actingAsInstructor();
    $foreign = Assessment::factory()->create();
    $assignment = Assessment::factory()->for($team)->assignment()->create();

    $this->{$method}(route($route, [$team, $foreign]))->assertNotFound();
    $this->{$method}(route($route, [$team, $assignment]))->assertNotFound();
    $this->{$method}(route($route, [$foreign->team, $foreign]))->assertForbidden();
})->with([
    ['get', 'quizzes.edit'],
    ['put', 'quizzes.update'],
    ['delete', 'quizzes.destroy'],
    ['get', 'quizzes.questions.index'],
    ['get', 'quizzes.access'],
    ['post', 'quizzes.publish'],
]);
