<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\DuplicateAssessment;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DuplicateAssessmentController extends Controller
{
    public function __invoke(Team $currentTeam, Assessment $assessment, Request $request, DuplicateAssessment $duplicate): RedirectResponse
    {
        $copy = $duplicate->handle($request->user(), $assessment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copied as a draft. Set its dates, then publish.')]);

        return to_route($copy->isAssignment() ? 'assignments.edit' : 'quizzes.edit', [$currentTeam, $copy]);
    }
}
