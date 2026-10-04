<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\ReleaseResults;
use App\Enums\AnswerGradingStatus;
use App\Enums\ReleaseMode;
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
                'show_answers' => $quiz->show_answers_after_release,
            ],
        ]);
    }

    public function release(Team $currentTeam, Assessment $quiz, Request $request, ReleaseResults $release): RedirectResponse
    {
        abort_if($quiz->isDraft() || $quiz->release_mode === ReleaseMode::Automatic, 422);

        $release->release($request->user(), $quiz);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results released. Students can open their results links.')]);

        return back();
    }

    public function unrelease(Team $currentTeam, Assessment $quiz, Request $request, ReleaseResults $release): RedirectResponse
    {
        abort_if($quiz->release_mode === ReleaseMode::Automatic, 422);

        $release->unrelease($request->user(), $quiz);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Results hidden from students again.')]);

        return back();
    }
}
