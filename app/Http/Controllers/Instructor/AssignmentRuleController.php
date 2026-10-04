<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assignments\SaveAssignmentRules;
use App\Enums\AutomatedCheck;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsAssignment;
use App\Http\Requests\Instructor\AssignmentRulesRequest;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentRuleController extends Controller
{
    use PresentsAssignment;

    public function index(Team $currentTeam, Assessment $assignment): Response
    {
        return Inertia::render('assignments/Rules', [
            ...$this->assignmentShell($currentTeam, $assignment),
            'rules' => $assignment->rules()->withCount('results')->get()->map(fn (AssignmentRule $rule) => [
                'id' => $rule->id,
                'kind' => $rule->kind->value,
                'title' => $rule->title,
                'description' => $rule->description,
                'check' => $rule->check?->value,
                'config' => (object) ($rule->config ?? []),
                'marks' => (float) $rule->marks,
                'has_results' => $rule->getAttribute('results_count') > 0,
            ]),
            'checks' => AutomatedCheck::options(),
            'gradedCount' => $this->gradedCount($assignment),
        ]);
    }

    public function update(AssignmentRulesRequest $request, Team $currentTeam, Assessment $assignment, SaveAssignmentRules $save): RedirectResponse
    {
        $save->handle($assignment, array_values((array) $request->validated('rules')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rules saved.')]);

        return back();
    }

    private function gradedCount(Assessment $assignment): int
    {
        return $assignment->submissions()
            ->where('is_current', true)
            ->whereIn('status', [SubmissionStatus::Final, SubmissionStatus::NeedsReview])
            ->count();
    }
}
