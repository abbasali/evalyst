<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function pendingInvitation(string $email = 'invited@example.com'): TeamInvitation
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'PHP 101']);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $email,
        'role' => TeamRole::Admin,
        'invited_by' => $owner->id,
    ]);
}

test('registration is invite-only', function () {
    $this->get(route('register'))->assertNotFound();
    $this->get(route('register', ['invitation' => 'unknown']))->assertNotFound();
});

test('the registration screen shows the invitation', function () {
    $invitation = pendingInvitation();

    $this->get(route('register', ['invitation' => $invitation->code]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Register')
            ->where('teamInvitation.teamName', 'PHP 101')
            ->where('teamInvitation.email', 'invited@example.com'));
});

test('an invited instructor can create an account and lands in the course', function () {
    $invitation = pendingInvitation();

    $response = $this->post(route('register.store'), [
        'name' => 'New Instructor',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation->code,
    ]);

    $this->assertAuthenticated();
    $user = User::where('email', 'invited@example.com')->first();

    $response->assertRedirect(route('dashboard', ['current_team' => $invitation->team->slug]));
    expect($user->belongsToTeam($invitation->team))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->ownedTeams()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('registration fails without a valid invitation', function (callable $makeInvitation, array $overrides) {
    $invitation = $makeInvitation();

    $this->post(route('register.store'), [
        'name' => 'Someone',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invitation' => $invitation?->code,
        ...$overrides,
    ])->assertSessionHasErrors();

    $this->assertGuest();
})->with([
    'no invitation' => [fn () => null, []],
    'expired' => [fn () => tap(pendingInvitation())->update(['expires_at' => now()->subDay()]), []],
    'already accepted' => [fn () => tap(pendingInvitation())->update(['accepted_at' => now()]), []],
    'different email' => [fn () => pendingInvitation(), ['email' => 'other@example.com']],
]);
