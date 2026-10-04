<?php

namespace App\Actions\Attempts;

use App\Actions\Audit\RecordAudit;
use App\Enums\AttemptEventType;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Instructor interventions on a participant's attempt (live monitor). Each one is audit-logged.
 */
class ManageAttempt
{
    public const RESUME_WINDOW_MINUTES = 10;

    public function __construct(private RecordAudit $audit, private SubmitAttempt $submit) {}

    /**
     * Shared-code mode: let the next resume (from any browser) within the window take over.
     */
    public function allowResume(User $user, Participant $participant): void
    {
        $attempt = $participant->attempt()->firstOrFail();
        $until = now()->addMinutes(self::RESUME_WINDOW_MINUTES);

        $attempt->update(['resume_override_until' => $until]);
        $attempt->events()->create(['type' => AttemptEventType::ResumeAllowed, 'occurred_at' => now()]);

        $this->audit->handle($user, $participant, 'attempt.allow_resume', ['after' => ['resume_override_until' => $until->toIso8601String()]]);
    }

    /**
     * Delete the attempt and its answers so the student can start again.
     */
    public function reset(User $user, Participant $participant): void
    {
        DB::transaction(function () use ($user, $participant) {
            $attempt = $participant->attempt()->lockForUpdate()->firstOrFail();

            $before = [
                'status' => $attempt->status->value,
                'started_at' => $attempt->started_at->toIso8601String(),
                'answered' => $attempt->answers()->whereNotNull('answered_at')->count(),
            ];

            $attempt->delete();

            $this->audit->handle($user, $participant, 'attempt.reset', ['before' => $before]);
        });
    }

    public function forceSubmit(User $user, Participant $participant): bool
    {
        $attempt = $participant->attempt()->firstOrFail();

        if (! $this->submit->handle($attempt, AttemptEventType::ForceSubmitted)) {
            return false;
        }

        $this->audit->handle($user, $participant, 'attempt.force_submit');

        return true;
    }
}
