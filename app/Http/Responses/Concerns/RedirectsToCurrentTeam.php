<?php

namespace App\Http\Responses\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    /**
     * The path to send the user to: their current course, or the "create your
     * first course" screen when they don't belong to any course yet.
     */
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->fallbackTeam();

        if (! $team) {
            return route('courses.start', absolute: false);
        }

        if (! $user->isCurrentTeam($team)) {
            $user->switchTeam($team);
        }

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }
}
