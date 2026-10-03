<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a new course and add the user as owner.
     *
     * @param  array{description?: string|null, timezone?: string}  $attributes
     */
    public function handle(User $user, string $name, bool $isPersonal = false, array $attributes = []): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal, $attributes) {
            $team = Team::create([
                ...array_filter($attributes, fn ($value) => $value !== null),
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $membership = $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }
}
