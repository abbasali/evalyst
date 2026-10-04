<?php

use App\Models\Assessment;
use App\Models\Participant;
use Illuminate\Support\Facades\DB;

test('the dashboard shows this course\'s open and upcoming work with bounded queries', function () {
    [$user, $team] = actingAsInstructor();
    $open = Assessment::factory()->open()->for($team)->create();
    Participant::factory()->count(3)->for($open)->create();
    Assessment::factory()->upcoming()->for($team)->create();
    Assessment::factory()->closed()->for($team)->create();
    Assessment::factory()->open()->create(); // another course

    DB::enableQueryLog();
    $this->get(route('dashboard', $team))
        ->assertInertia(fn ($page) => $page
            ->has('overview.active', 1)
            ->where('overview.active.0.participants', 3)
            ->has('overview.upcoming', 1)
            ->has('overview.unreleased', 1)
            ->where('overview.isEmpty', false));

    expect(count(DB::getQueryLog()))->toBeLessThan(25);
});

test('an empty course shows the getting-started steps', function () {
    [, $team] = actingAsInstructor();

    $this->get(route('dashboard', $team))->assertInertia(fn ($page) => $page->where('overview.isEmpty', true));
});
