<?php

use App\Actions\Audit\RecordAudit;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;

test('instructors without a course are sent to onboarding', function () {
    $user = User::factory()->withoutCourse()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('courses.start'));

    $this->get(route('courses.start'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('onboarding/Start'));
});

test('onboarding redirects instructors who already have a course', function () {
    [, $team] = actingAsInstructor();

    $this->get(route('courses.start'))->assertRedirect(route('dashboard', $team));
});

test('a course is created with description and timezone, then opened', function () {
    $user = User::factory()->withoutCourse()->create();

    $response = $this->actingAs($user)->post(route('teams.store'), [
        'name' => 'PHP 101',
        'description' => 'Basics of PHP',
        'timezone' => 'Europe/London',
    ]);

    $team = Team::where('name', 'PHP 101')->sole();
    $response->assertRedirect(route('dashboard', $team));
    expect($team->description)->toBe('Basics of PHP')
        ->and($team->timezone)->toBe('Europe/London')
        ->and($user->fresh()->ownsTeam($team))->toBeTrue();
});

test('course settings validate the timezone', function () {
    [, $team] = actingAsInstructor();

    $this->patch(route('teams.update', $team), ['name' => 'X', 'timezone' => 'Mars/Base'])
        ->assertSessionHasErrors('timezone');

    $this->patch(route('teams.update', $team), ['name' => 'X', 'timezone' => 'Asia/Dubai'])
        ->assertSessionHasNoErrors();

    expect($team->fresh()->timezone)->toBe('Asia/Dubai');
});

test('course navigation is only available to members', function (string $route) {
    [, $team] = actingAsInstructor();
    $this->get(route($route, $team))->assertOk();

    $outsider = Team::factory()->create();
    $this->get(route($route, $outsider))->assertForbidden();
})->with(['dashboard', 'questions.index', 'quizzes.index', 'assignments.index', 'students.index', 'review.index']);

test('only the owner can delete a course', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    [$colleague] = actingAsInstructor($team);

    $this->actingAs($colleague)->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertForbidden();
});

test('make-instructor creates a verified instructor with a course', function () {
    $this->artisan('evalyst:make-instructor', [
        'email' => 'Teacher@Example.com',
        '--name' => 'Teacher',
        '--password' => 'secret-password',
        '--course' => 'Laravel 13',
    ])->assertSuccessful();

    $user = User::where('email', 'teacher@example.com')->sole();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->currentTeam->name)->toBe('Laravel 13');
});

test('audit logs are recorded against the subject course', function () {
    [$user, $team] = actingAsInstructor();

    $log = app(RecordAudit::class)->handle($user, $team, 'course.test', ['before' => 1, 'after' => 2], 'note');

    expect($log->team_id)->toBe($team->id)
        ->and($log->changes)->toBe(['before' => 1, 'after' => 2])
        ->and(AuditLog::forCourse($team)->count())->toBe(1);
});

test('logged-in instructors without a course are sent to onboarding from guest pages', function () {
    $user = User::factory()->withoutCourse()->create();

    $this->actingAs($user)->get(route('login'))->assertRedirect(route('courses.start'));
});

test('invitations can only be accepted with a verified email', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
    $invitation = $owner->currentTeam->invitations()->create([
        'email' => 'invitee@example.com',
        'role' => 'admin',
        'invited_by' => $owner->id,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($invitee)->post(route('invitations.accept', $invitation))
        ->assertRedirect(route('verification.notice'));

    expect($invitee->fresh()->belongsToTeam($owner->currentTeam))->toBeFalse();
});

test('removing yourself from a course behaves like leaving it', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    [$colleague] = actingAsInstructor($team);

    $this->delete(route('teams.members.destroy', [$team, $colleague]))
        ->assertRedirect(route('teams.index'));

    expect($colleague->fresh()->belongsToTeam($team))->toBeFalse();
});
