<?php

use App\Enums\AssessmentType;
use App\Enums\LatePolicy;
use App\Models\Assessment;
use App\Models\Student;

function assignmentPayload(array $overrides = []): array
{
    return [
        'title' => 'Blog project',
        'instructions' => 'Build a blog.',
        'opens_at' => '',
        'closes_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'late_policy' => 'penalty',
        'penalty_type' => 'per_day',
        'penalty_value' => 2,
        'penalty_cap' => 6,
        'grace_minutes' => 10,
        'hard_cutoff_at' => '',
        'allow_resubmission' => true,
        'show_rules_to_students' => true,
        'extra_ignored_paths' => "docs/**\n\n docs/** ",
        'release_mode' => 'manual',
        'auto_publish_threshold' => '',
        'access_mode' => 'roster',
        ...$overrides,
    ];
}

test('an assignment is created with times converted from the course timezone', function () {
    [, $team] = actingAsInstructor();
    $team->update(['timezone' => 'Asia/Kolkata']);

    $this->post(route('assignments.store', $team), assignmentPayload(['closes_at' => '2026-12-01T18:00']))
        ->assertRedirect();

    $assignment = Assessment::sole();
    expect($assignment->type)->toBe(AssessmentType::Assignment)
        ->and($assignment->closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 12:30')
        ->and($assignment->late_policy)->toBe(LatePolicy::Penalty)
        ->and($assignment->extra_ignored_paths)->toBe(['docs/**']);
});

test('penalty fields and the cutoff are validated', function () {
    [, $team] = actingAsInstructor();

    $this->post(route('assignments.store', $team), assignmentPayload(['penalty_value' => '', 'penalty_type' => '']))
        ->assertSessionHasErrors(['penalty_value', 'penalty_type']);

    $this->post(route('assignments.store', $team), assignmentPayload(['hard_cutoff_at' => now()->format('Y-m-d\TH:i')]))
        ->assertSessionHasErrors('hard_cutoff_at');

    $this->post(route('assignments.store', $team), assignmentPayload(['penalty_cap' => 1]))
        ->assertSessionHasErrors('penalty_cap');
});

test('another course gets a 404, and a quiz is not an assignment', function () {
    $assignment = Assessment::factory()->assignment()->create();
    $quiz = Assessment::factory()->create();
    [, $team] = actingAsInstructor();
    $quiz->update(['team_id' => $team->id]);

    $this->get(route('assignments.edit', [$team, $assignment]))->assertNotFound();
    $this->get(route('assignments.access', [$team, $assignment]))->assertNotFound();
    $this->get(route('assignments.edit', [$team, $quiz]))->assertNotFound();
});

test('the shared access actions work for assignments', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->for($team)->create();
    $student = Student::factory()->for($team)->create();

    $this->post(route('assignments.participants.store', [$team, $assignment]), ['student_ids' => [$student->id]])
        ->assertRedirect();

    expect($assignment->participants()->count())->toBe(1);
    $this->get(route('assignments.access', [$team, $assignment]))->assertInertia(fn ($page) => $page->component('assignments/Access')->has('participants', 1));
});

test('an assignment can be set to never release results to students', function () {
    [, $team] = actingAsInstructor();

    $this->post(route('assignments.store', $team), assignmentPayload(['release_results' => false]))->assertSessionHasNoErrors();

    expect(Assessment::sole()->release_results)->toBeFalse();
});
