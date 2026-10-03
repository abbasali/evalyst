<?php

namespace App\Http\Controllers\Courses;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourseStartController extends Controller
{
    /**
     * Onboarding for instructors who don't belong to any course yet.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($team = $user->currentTeam ?? $user->fallbackTeam()) {
            return to_route('dashboard', ['current_team' => $team->slug]);
        }

        return Inertia::render('onboarding/Start', [
            'timezones' => timezone_identifiers_list(),
            'defaultTimezone' => Team::DEFAULT_TIMEZONE,
            'pendingInvitations' => TeamInvitation::query()
                ->with(['inviter', 'team'])
                ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now()))
                ->latest()
                ->get()
                ->map(fn (TeamInvitation $invitation) => [
                    'code' => $invitation->code,
                    'inviterName' => $invitation->inviter->name,
                    'team' => ['name' => $invitation->team->name, 'slug' => $invitation->team->slug],
                ]),
        ]);
    }
}
