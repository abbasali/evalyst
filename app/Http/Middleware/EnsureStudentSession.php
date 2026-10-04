<?php

namespace App\Http\Middleware;

use App\Enums\AccessMode;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Participant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Students have no accounts: after joining, the session holds their participant ID.
 * Checks the route's assessment/attempt belongs to that participant (see 01-architecture.md).
 */
class EnsureStudentSession
{
    public const SESSION_KEY = 'student.participant_id';

    public function handle(Request $request, Closure $next): Response
    {
        $participant = Participant::query()
            ->with(['assessment.team', 'student'])
            ->whereKey((int) $request->session()->get(self::SESSION_KEY))
            ->first();

        if (! $participant) {
            return $request->expectsJson()
                ? response()->json(['reason' => 'session', 'redirect' => route('student.join')], 401)
                : to_route('student.join');
        }

        $assessment = $request->route('assessment');
        $attempt = $request->route('attempt');

        abort_if($assessment instanceof Assessment && $assessment->id !== $participant->assessment_id, 403);
        abort_if($attempt instanceof Attempt && $attempt->participant_id !== $participant->id, 403);

        // Shared-code attempts continue only in the browser that started them (or after "allow resume").
        if ($attempt instanceof Attempt && $attempt->isInProgress() && ! static::mayContinue($request, $participant, $attempt)) {
            $landing = route('student.landing', $participant->assessment->public_id);

            return $request->expectsJson()
                ? response()->json(['reason' => 'elsewhere', 'redirect' => $landing], 409)
                : redirect($landing);
        }

        $request->attributes->set('participant', $participant);

        Inertia::share('studentContext', [
            'course' => $participant->assessment->team->name,
            'title' => $participant->assessment->title,
            'name' => $participant->student->name,
            'roll_number' => $participant->student->roll_number,
        ]);

        return $next($request);
    }

    /**
     * Roster codes identify the student, so any device may continue. Shared codes need the
     * resume cookie set when the attempt started.
     */
    public static function mayContinue(Request $request, Participant $participant, Attempt $attempt): bool
    {
        if ($participant->assessment->access_mode === AccessMode::Roster) {
            return true;
        }

        $token = $request->cookie($attempt->cookieName());

        return is_string($token) && hash_equals($attempt->resume_token, hash('sha256', $token));
    }
}
