<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Create a user from a course invitation. Accounts are invite-only (D-004).
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'invitation' => ['required', 'string'],
        ])->validate();

        $invitation = TeamInvitation::findPending($input['invitation']);

        if (! $invitation) {
            throw ValidationException::withMessages([
                'email' => __('This invitation is no longer valid. Ask a colleague to invite you again.'),
            ]);
        }

        if (strtolower($invitation->email) !== strtolower($input['email'])) {
            throw ValidationException::withMessages([
                'email' => __('This invitation was sent to a different email address.'),
            ]);
        }

        return DB::transaction(function () use ($input, $invitation) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $invitation->email,
                'password' => $input['password'],
            ]);

            // The invitation email proves ownership of the address.
            $user->forceFill(['email_verified_at' => now()])->save();

            $invitation->team->memberships()->create([
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);

            $invitation->update(['accepted_at' => now()]);

            $user->switchTeam($invitation->team);

            return $user;
        });
    }
}
