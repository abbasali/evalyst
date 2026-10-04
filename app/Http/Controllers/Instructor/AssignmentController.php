<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\SaveQuiz;
use App\Actions\Assignments\RecalculateLatePenalty;
use App\Enums\AccessMode;
use App\Enums\AssessmentType;
use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Enums\ReleaseMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsAssignment;
use App\Http\Requests\Instructor\AssignmentRequest;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentController extends Controller
{
    use PresentsAssignment;

    public const TABS = ['open', 'upcoming', 'draft', 'closed', 'archived'];

    public function index(Request $request, Team $currentTeam): Response
    {
        $request->validate(['tab' => ['nullable', Rule::in(self::TABS)]]);

        $counts = collect(self::TABS)->mapWithKeys(fn (string $tab) => [
            $tab => $currentTeam->assignments()->inState($tab)->count(),
        ]);

        $tab = $request->input('tab')
            ?? collect(['open', 'upcoming', 'draft', 'closed'])->first(fn (string $tab) => $counts[$tab] > 0, 'open');

        $assignments = $currentTeam->assignments()
            ->inState($tab)
            ->withCount(['rules', 'participants', 'submissions as submitted_count' => fn ($query) => $query->where('is_current', true)])
            ->withSum('rules', 'marks')
            ->orderByRaw($tab === 'upcoming' ? 'opens_at asc' : ($tab === 'open' ? 'closes_at asc' : 'updated_at desc'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Assessment $assignment) => [
                'id' => $assignment->id,
                'title' => $assignment->title,
                'state' => $assignment->state(),
                'access_mode' => $assignment->access_mode->value,
                'opens_at' => $assignment->opens_at?->toIso8601String(),
                'closes_at' => $assignment->closes_at->toIso8601String(),
                'rules_count' => $assignment->rules_count,
                'max_score' => (float) $assignment->getAttribute('rules_sum_marks'),
                'participants_count' => $assignment->participants_count,
                'submitted_count' => (int) $assignment->getAttribute('submitted_count'),
            ]);

        return Inertia::render('assignments/Index', [
            'assignments' => $assignments,
            'tab' => $tab,
            'counts' => $counts,
        ]);
    }

    public function create(Team $currentTeam): Response
    {
        return Inertia::render('assignments/Settings', [
            'form' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(AssignmentRequest $request, Team $currentTeam, SaveQuiz $save): RedirectResponse
    {
        $assignment = $save->handle($currentTeam, $request->user(), $request->assignmentAttributes(), type: AssessmentType::Assignment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Assignment created. Now add the grading rules.')]);

        return to_route('assignments.rules', [$currentTeam, $assignment]);
    }

    public function edit(Team $currentTeam, Assessment $assignment): Response
    {
        $timezone = $currentTeam->timezone;
        $local = fn ($time) => $time?->copy()->setTimezone($timezone)->format('Y-m-d\TH:i') ?? '';

        return Inertia::render('assignments/Settings', [
            ...$this->assignmentShell($currentTeam, $assignment),
            'form' => [
                'title' => $assignment->title,
                'instructions' => $assignment->instructions ?? '',
                'opens_at' => $local($assignment->opens_at),
                'closes_at' => $local($assignment->closes_at),
                'late_policy' => $assignment->late_policy->value ?? LatePolicy::NotAllowed->value,
                'penalty_type' => $assignment->penalty_type->value ?? PenaltyType::PerDay->value,
                'penalty_value' => $assignment->penalty_value !== null ? (float) $assignment->penalty_value : '',
                'penalty_cap' => $assignment->penalty_cap !== null ? (float) $assignment->penalty_cap : '',
                'grace_minutes' => $assignment->grace_minutes,
                'hard_cutoff_at' => $local($assignment->hard_cutoff_at),
                'allow_resubmission' => $assignment->allow_resubmission,
                'show_rules_to_students' => $assignment->show_rules_to_students,
                'extra_ignored_paths' => implode("\n", $assignment->extra_ignored_paths ?? []),
                'release_mode' => $assignment->release_mode->value,
                'auto_publish_threshold' => $assignment->auto_publish_threshold !== null ? (float) $assignment->auto_publish_threshold : '',
                'access_mode' => $assignment->access_mode->value,
            ],
            'accessModeLocked' => ! $assignment->isDraft() || $assignment->participants()->exists(),
            ...$this->formOptions(),
        ]);
    }

    public function update(AssignmentRequest $request, Team $currentTeam, Assessment $assignment, SaveQuiz $save, RecalculateLatePenalty $recalculate): RedirectResponse
    {
        DB::transaction(function () use ($save, $currentTeam, $request, $assignment, $recalculate) {
            $save->handle($currentTeam, $request->user(), $request->assignmentAttributes(), $assignment);

            // The deadline or late policy changed: keep current submissions consistent.
            if ($assignment->wasChanged(['closes_at', 'late_policy', 'penalty_type', 'penalty_value', 'penalty_cap', 'grace_minutes'])) {
                $assignment->submissions()->where('is_current', true)->with('participant.assessment')->lockForUpdate()->get()
                    ->each(fn ($submission) => $recalculate->handle($submission));
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    public function destroy(Team $currentTeam, Assessment $assignment): RedirectResponse
    {
        Gate::authorize('delete', $assignment);

        $assignment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Assignment deleted.')]);

        return to_route('assignments.index', $currentTeam);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'releaseModes' => ReleaseMode::options(),
            'accessModes' => AccessMode::options(),
            'latePolicies' => LatePolicy::options(),
            'penaltyTypes' => PenaltyType::options(),
            'defaultThreshold' => (float) config('evalyst.ai.auto_publish_threshold'),
        ];
    }
}
