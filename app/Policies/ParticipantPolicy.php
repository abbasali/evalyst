<?php

namespace App\Policies;

use App\Models\Participant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ParticipantPolicy
{
    public function delete(User $user, Participant $participant): Response
    {
        return $participant->attempt()->exists() || $participant->submissions()->exists()
            ? Response::deny(__(':name has already started or submitted and can\'t be removed.', ['name' => $participant->student->name]))
            : Response::allow();
    }
}
