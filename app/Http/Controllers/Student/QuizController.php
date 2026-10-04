<?php

namespace App\Http\Controllers\Student;

use App\Actions\Attempts\StartAttempt;
use App\Actions\Attempts\SubmitAttempt;
use App\Enums\AttemptEventType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesParticipant;
use App\Http\Middleware\EnsureStudentSession;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The quiz landing page, starting and resuming.
 */
class QuizController extends Controller
{
    use ResolvesParticipant;

    public function show(Request $request, Assessment $assessment, SubmitAttempt $submit): Response
    {
        $participant = $this->participant($request);
        $attempt = $participant->attempt;

        if ($attempt) {
            $submit->expireIfOverdue($attempt);
        }

        return Inertia::render('student/quiz/Landing', [
            'quiz' => [
                'public_id' => $assessment->public_id,
                'title' => $assessment->title,
                'instructions' => $assessment->instructions,
                'duration_minutes' => $assessment->duration_minutes,
                'questions_count' => $assessment->assessmentQuestions()->count(),
                'max_score' => $assessment->maxScore(),
                'opens_at' => $assessment->opens_at?->toIso8601String(),
                'closes_at' => $assessment->closes_at->toIso8601String(),
                'timezone' => $assessment->team->timezone,
                'track_focus' => $assessment->track_focus,
                'one_way_navigation' => $assessment->one_way_navigation,
                'require_fullscreen' => $assessment->require_fullscreen,
            ],
            'state' => $this->state($request, $participant, $attempt),
            'attempt' => $attempt ? [
                'public_id' => $attempt->public_id,
                'deadline_at' => $attempt->deadline_at->toIso8601String(),
                'position' => $attempt->furthest_position,
            ] : null,
        ]);
    }

    public function start(Request $request, Assessment $assessment, StartAttempt $start): RedirectResponse
    {
        $participant = $this->participant($request);

        [$attempt, $token] = $start->handle($participant);

        if ($token !== null) {
            $this->issueResumeCookie($attempt, $token);
        }

        return to_route('student.question', [$attempt->public_id, $attempt->furthest_position]);
    }

    /**
     * Continue an in-progress attempt. In shared-code mode a new browser needs the instructor's
     * "allow resume" window; using it issues a fresh cookie and closes the window.
     */
    public function resume(Request $request, Assessment $assessment): RedirectResponse
    {
        $participant = $this->participant($request);
        $attempt = $participant->attempt;

        if (! $attempt?->isInProgress()) {
            return to_route('student.landing', $assessment->public_id);
        }

        if (! EnsureStudentSession::mayContinue($request, $participant, $attempt)) {
            $token = Str::random(64);

            // Single use: only one browser can consume the window, even if two try at once.
            $claimed = Attempt::query()
                ->whereKey($attempt->id)
                ->where('resume_override_until', '>', now())
                ->update(['resume_token' => hash('sha256', $token), 'resume_override_until' => null]);

            if ($claimed === 0) {
                return to_route('student.landing', $assessment->public_id);
            }

            $this->issueResumeCookie($attempt->refresh(), $token);
        }

        $attempt->events()->create(['type' => AttemptEventType::Resumed, 'occurred_at' => now()]);

        return to_route('student.question', [$attempt->public_id, $attempt->furthest_position]);
    }

    /**
     * not_started | upcoming | closed | in_progress | blocked | submitted
     */
    private function state(Request $request, Participant $participant, ?Attempt $attempt): string
    {
        $assessment = $participant->assessment;

        return match (true) {
            $attempt === null => match (true) {
                $assessment->isOpen() => 'not_started',
                $assessment->isUpcoming() => 'upcoming',
                default => 'closed',
            },
            ! $attempt->isInProgress() => 'submitted',
            EnsureStudentSession::mayContinue($request, $participant, $attempt),
            (bool) $attempt->resume_override_until?->isFuture() => 'in_progress',
            default => 'blocked',
        };
    }

    private function issueResumeCookie(Attempt $attempt, string $token): void
    {
        $minutes = (int) ceil(now()->diffInMinutes($attempt->deadline_at->addDay()));

        Cookie::queue(Cookie::make($attempt->cookieName(), $token, $minutes, httpOnly: true, sameSite: 'lax'));
    }
}
