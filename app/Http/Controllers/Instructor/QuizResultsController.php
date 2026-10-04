<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\ReleaseResults;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsQuiz;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The results tab: one row per participant, plus releasing results to students.
 */
class QuizResultsController extends Controller
{
    use PresentsQuiz;

    public function show(Team $currentTeam, Assessment $quiz): Response
    {
        $participants = $quiz->participants()
            ->with(['student', 'attempt' => fn ($query) => $query->withCount([
                'answers as waiting_count' => fn ($query) => $query->whereIn('grading_status', [AnswerGradingStatus::NeedsReview, AnswerGradingStatus::Failed]),
            ])])
            ->get()
            ->sortBy(fn (Participant $participant) => $participant->student->roll_number, SORT_NATURAL)
            ->values();

        $rows = $participants->map(fn (Participant $participant) => [
            'id' => $participant->id,
            'name' => $participant->student->name,
            'roll_number' => $participant->student->roll_number,
            'results_url' => route('student.results', $participant->public_id),
            'attempt_id' => $participant->attempt?->id,
            'status' => $participant->attempt->status->value ?? 'not_started',
            'score' => $participant->attempt?->score !== null ? (float) $participant->attempt->score : null,
            'max_score' => $participant->attempt ? (float) $participant->attempt->max_score : null,
            'submitted_at' => $participant->attempt?->submitted_at?->toIso8601String(),
            'auto_submitted' => (bool) $participant->attempt?->auto_submitted,
            'focus_lost' => $participant->attempt->focus_lost_count ?? 0,
            'waiting' => (int) ($participant->attempt?->getAttribute('waiting_count') ?? 0),
        ]);

        $graded = $rows->where('status', 'graded');

        return Inertia::render('quizzes/Results', [
            ...$this->quizShell($currentTeam, $quiz),
            'rows' => $rows,
            'summary' => [
                'participants' => $rows->count(),
                'submitted' => $rows->whereNotIn('status', ['not_started', 'in_progress'])->count(),
                'graded' => $graded->count(),
                'waiting' => $rows->sum('waiting'),
                'average' => $graded->isEmpty() ? null : round((float) $graded->avg('score'), 2),
            ],
            'release' => [
                'mode' => $quiz->release_mode->value,
                'released' => $quiz->resultsReleased(),
                'released_at' => $quiz->results_released_at?->toIso8601String(),
                'can_unrelease' => $quiz->results_released_at !== null && ! $quiz->autoReleaseDue(),
                'show_answers' => $quiz->show_answers_after_release,
            ],
        ]);
    }

    public function release(Team $currentTeam, Assessment $quiz, Request $request, ReleaseResults $release): RedirectResponse
    {
        // Also allowed in automatic mode, to release early.
        abort_if($quiz->isDraft(), 422);

        // Correct answers must not reach students while classmates can still take the quiz.
        if ($quiz->isQuiz() && $quiz->show_answers_after_release && ! $quiz->autoReleaseDue()
            && (! $quiz->isClosed() || $quiz->attempts()->where('attempts.status', AttemptStatus::InProgress)->exists())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Students can still take this quiz, and releasing would show them the correct answers. Wait until it closes, or turn off "Show correct answers" in Settings first.')]);

            return back();
        }

        $release->release($request->user(), $quiz);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results released. Students can open their results links.')]);

        return back();
    }

    public function unrelease(Team $currentTeam, Assessment $quiz, Request $request, ReleaseResults $release): RedirectResponse
    {
        // Once automatic release is due, hiding results again isn't possible.
        abort_if($quiz->autoReleaseDue(), 422, __('Results are released automatically now; switch to manual release to hide them.'));

        $release->unrelease($request->user(), $quiz);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results hidden from students again.')]);

        return back();
    }
}
