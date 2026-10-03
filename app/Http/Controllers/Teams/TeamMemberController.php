<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Remove the specified team member.
     */
    public function destroy(Request $request, Team $team, User $user): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($user), 403, __('The course owner cannot be removed.'));

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($team)) {
            $user->switchToFallbackTeam($team);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        // Removing yourself is the same as leaving the course.
        if ($user->is($request->user())) {
            return $user->current_team_id ? to_route('teams.index') : to_route('courses.start');
        }

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
