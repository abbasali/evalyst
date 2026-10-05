<?php

namespace App\Http\Controllers\Student;

use App\Actions\Assignments\SubmitRepository;
use App\Enums\LateOverride;
use App\Grading\LatePenaltyCalculator;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesParticipant;
use App\Jobs\GradeSubmission;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use App\Models\Submission;
use App\Support\LatePolicySummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An assignment as the student sees it: the statement, rules, their deadline and the submit form.
 */
class AssignmentController extends Controller
{
    use ResolvesParticipant;

    public function show(Request $request, Assessment $assessment): Response
    {
        $participant = $this->participant($request);
        $calculator = LatePenaltyCalculator::for($assessment, $participant);
        $decision = $calculator->canSubmit(now());
        $submissions = $participant->submissions()->latest('submitted_at')->limit(20)->get();
        $hasCurrent = $submissions->contains(fn (Submission $submission) => $submission->is_current);

        return Inertia::render('student/assignment/Show', [
            'assignment' => [
                'public_id' => $assessment->public_id,
                'title' => $assessment->title,
                'instructions' => $assessment->instructions,
                'timezone' => $assessment->team->timezone,
                'opens_at' => $assessment->opens_at?->toIso8601String(),
                'deadline' => $calculator->effectiveDeadline()->toIso8601String(),
                'has_deadline_override' => $participant->deadline_override_at !== null,
                'late_policy' => LatePolicySummary::for($assessment, $participant),
                'max_score' => $assessment->maxScore(),
                'rules' => $assessment->show_rules_to_students
                    ? $assessment->rules()->get()->map(fn (AssignmentRule $rule) => ['title' => $rule->title, 'marks' => (float) $rule->marks])
                    : null,
            ],
            'canSubmit' => [
                'allowed' => $decision->allowed && (! $hasCurrent || ($assessment->allow_resubmission && (! $decision->late || $participant->late_override === LateOverride::Allow))),
                'late' => $decision->late,
                'reason' => $decision->allowed
                    ? ($hasCurrent ? __('You can\'t replace your submission now.') : null)
                    : $decision->reason,
            ],
            'submissions' => $submissions->map(fn (Submission $submission) => [
                'id' => $submission->public_id,
                'repo_url' => $submission->repo_url,
                'commit_url' => $submission->commitUrl(),
                'short_sha' => $submission->shortSha(),
                'submitted_at' => $submission->submitted_at->toIso8601String(),
                'minutes_late' => $submission->minutes_late,
                'is_current' => $submission->is_current,
            ]),
            // No results link when the instructor doesn't release results to students.
            'resultsUrl' => $assessment->release_results ? route('student.results', $participant->public_id) : null,
        ]);
    }

    public function submit(Request $request, Assessment $assessment, SubmitRepository $submit): RedirectResponse
    {
        abort_unless($assessment->isAssignment(), 404);

        $request->validate(['repo_url' => ['required', 'string', 'max:300']]);

        $submission = $submit->handle($this->participant($request), $request->string('repo_url')->value());

        GradeSubmission::dispatch($submission->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => $submission->minutes_late > 0
            ? __('Submitted (late). We recorded commit :sha.', ['sha' => $submission->shortSha()])
            : __('Submitted. We recorded commit :sha.', ['sha' => $submission->shortSha()])]);

        return back();
    }
}
