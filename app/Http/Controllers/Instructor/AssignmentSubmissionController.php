<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assignments\ApplyParticipantOverride;
use App\Enums\LateOverride;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsAssignment;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The submissions tab: one row per participant with their current submission, lateness and
 * score, plus per-student deadline/penalty overrides.
 */
class AssignmentSubmissionController extends Controller
{
    use PresentsAssignment;

    public function index(Team $currentTeam, Assessment $assignment): Response
    {
        $timezone = $currentTeam->timezone;
        $participants = $assignment->participants()
            ->with(['student', 'currentSubmission'])
            ->get()
            ->sortBy(fn (Participant $participant) => $participant->student->roll_number, SORT_NATURAL)
            ->values();

        $rows = $participants->map(function (Participant $participant) use ($timezone) {
            $submission = $participant->currentSubmission;

            return [
                'id' => $participant->id,
                'name' => $participant->student->name,
                'roll_number' => $participant->student->roll_number,
                'results_url' => route('student.results', $participant->public_id),
                'submission' => $submission ? [
                    'id' => $submission->id,
                    'repo_url' => $submission->repo_url,
                    'commit_url' => $submission->commitUrl(),
                    'short_sha' => $submission->shortSha(),
                    'submitted_at' => $submission->submitted_at->toIso8601String(),
                    'minutes_late' => $submission->minutes_late,
                    'status' => $submission->status->value,
                    'raw_score' => $submission->raw_score !== null ? (float) $submission->raw_score : null,
                    'penalty' => (float) $submission->penalty,
                    'score' => $submission->score !== null ? (float) $submission->score : null,
                    'max_score' => (float) $submission->max_score,
                ] : null,
                'has_overrides' => $participant->hasOverrides(),
                'overrides' => [
                    'deadline_override_at' => $participant->deadline_override_at?->copy()->setTimezone($timezone)->format('Y-m-d\TH:i') ?? '',
                    'late_override' => $participant->late_override->value ?? '',
                    'penalty_waived' => $participant->penalty_waived,
                    'penalty_override' => $participant->penalty_override !== null ? (float) $participant->penalty_override : '',
                    'override_note' => $participant->override_note ?? '',
                ],
            ];
        });

        $submitted = $rows->filter(fn (array $row) => $row['submission'] !== null);

        return Inertia::render('assignments/Submissions', [
            ...$this->assignmentShell($currentTeam, $assignment),
            'rows' => $rows,
            'summary' => [
                'participants' => $rows->count(),
                'submitted' => $submitted->count(),
                'late' => $submitted->filter(fn (array $row) => $row['submission']['minutes_late'] > 0)->count(),
                'graded' => $submitted->filter(fn (array $row) => $row['submission']['status'] === 'final')->count(),
            ],
            'release' => [
                'mode' => $assignment->release_mode->value,
                'released' => $assignment->resultsReleased(),
                'released_at' => $assignment->results_released_at?->toIso8601String(),
            ],
            'lateOverrides' => LateOverride::options(),
        ]);
    }

    public function overrides(Request $request, Team $currentTeam, Assessment $assignment, Participant $participant, ApplyParticipantOverride $apply): RedirectResponse
    {
        $data = $request->validate([
            'deadline_override_at' => ['nullable', 'date'],
            'late_override' => ['nullable', Rule::enum(LateOverride::class)],
            'penalty_waived' => ['boolean'],
            'penalty_override' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'override_note' => ['required', 'string', 'max:1000'],
        ]);

        $apply->handle($request->user(), $participant, [
            'deadline_override_at' => ! empty($data['deadline_override_at']) ? Date::parse($data['deadline_override_at'], $currentTeam->timezone)->utc() : null,
            'late_override' => ! empty($data['late_override']) ? LateOverride::from($data['late_override']) : null,
            'penalty_waived' => $request->boolean('penalty_waived'),
            'penalty_override' => isset($data['penalty_override']) && $data['penalty_override'] !== '' ? round((float) $data['penalty_override'], 2) : null,
            'override_note' => $data['override_note'],
        ]);

        $published = $participant->currentSubmission()->whereNotNull('published_at')->exists();

        Inertia::flash('toast', ['type' => 'success', 'message' => $published && $assignment->resultsReleased()
            ? __('Saved. :name\'s published score was updated, and they can see the change.', ['name' => $participant->student->name])
            : __('Overrides saved for :name.', ['name' => $participant->student->name])]);

        return back();
    }
}
