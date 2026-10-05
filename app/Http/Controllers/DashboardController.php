<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\AttemptStatus;
use App\Enums\ReleaseMode;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Queries\ReviewInboxQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Team $currentTeam): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            ...$this->overview($currentTeam),
        ]);
    }

    /**
     * The course at a glance, in a handful of aggregate queries.
     *
     * @return array<string, mixed>
     */
    private function overview(Team $team): array
    {
        $counts = [
            'participants',
            'attempts as started_count',
            'attempts as quiz_submitted_count' => fn ($query) => $query->where('attempts.status', '!=', AttemptStatus::InProgress),
            'submissions as assignment_submitted_count' => fn ($query) => $query->where('is_current', true),
        ];

        $row = fn (Assessment $assessment) => [
            'id' => $assessment->id,
            'type' => $assessment->type->value,
            'title' => $assessment->title,
            'opens_at' => $assessment->opens_at?->toIso8601String(),
            'closes_at' => $assessment->closes_at->toIso8601String(),
            'participants' => (int) $assessment->participants_count,
            'started' => (int) $assessment->getAttribute('started_count'),
            'submitted' => (int) ($assessment->isAssignment()
                ? $assessment->getAttribute('assignment_submitted_count')
                : $assessment->getAttribute('quiz_submitted_count')),
        ];

        $active = $team->assessments()->inState('open')->withCount($counts)->orderBy('closes_at')->limit(10)->get()->map($row);
        $upcoming = $team->assessments()->inState('upcoming')->where('opens_at', '<=', now()->addDays(14))
            ->withCount('participants')->orderBy('opens_at')->limit(5)->get()->map($row);
        // Closed with results still hidden: manual mode, or automatic mode held back (a student is
        // still mid-attempt, or a personal deadline hasn't passed). Skips ones never released to students.
        $unreleased = $team->assessments()->inState('closed')->withCount($counts)
            ->where('release_results', true)
            ->whereNull('results_released_at')
            ->latest('closes_at')
            ->limit(10)
            ->get()
            ->filter(fn (Assessment $assessment) => $assessment->release_mode === ReleaseMode::Manual || ! $assessment->autoReleaseDue())
            ->take(5)
            ->values()
            ->map($row);

        $activity = AuditLog::query()
            ->where('team_id', $team->id)
            ->with('user:id,name')
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'created_at' => $log->created_at?->toIso8601String(),
                'note' => $log->note,
            ]);

        return [
            'overview' => [
                'active' => $active,
                'upcoming' => $upcoming,
                'unreleased' => $unreleased,
                'needsReview' => ReviewInboxQuery::count($team),
                'activity' => $activity,
                'aiSpendMonth' => round((float) $team->aiRuns()->where('created_at', '>=', now($team->timezone)->startOfMonth()->utc())->sum('cost_usd'), 2),
                'isEmpty' => ! $team->assessments()->where('status', '!=', AssessmentStatus::Archived)->exists(),
                'hasStudents' => $team->students()->exists(),
                'hasQuestions' => $team->questions()->exists(),
            ],
        ];
    }
}
