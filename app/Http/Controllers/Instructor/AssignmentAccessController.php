<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsAssignment;
use App\Models\Assessment;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The access tab of an assignment. Its actions (add students, codes, shared code) are served by
 * QuizAccessController through the `assignment` route binding.
 */
class AssignmentAccessController extends Controller
{
    use PresentsAssignment;

    public function show(Team $currentTeam, Assessment $assignment): Response
    {
        return Inertia::render('assignments/Access', [
            ...$this->assignmentShell($currentTeam, $assignment),
            ...QuizAccessController::accessProps($currentTeam, $assignment),
        ]);
    }
}
