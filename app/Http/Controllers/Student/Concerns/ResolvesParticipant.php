<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\Participant;
use Illuminate\Http\Request;

trait ResolvesParticipant
{
    /**
     * The participant loaded by EnsureStudentSession.
     */
    protected function participant(Request $request): Participant
    {
        $participant = $request->attributes->get('participant');
        abort_unless($participant instanceof Participant, 403);

        return $participant;
    }
}
