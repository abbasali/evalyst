<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\SaveQuiz;
use App\Enums\AccessMode;
use App\Enums\AttemptStatus;
use App\Enums\ReleaseMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsQuiz;
use App\Http\Requests\Instructor\QuizRequest;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuizController extends Controller
{
    use PresentsQuiz;

    public const TABS = ['open', 'upcoming', 'draft', 'closed', 'archived'];

    public function index(Request $request, Team $currentTeam): Response
    {
        $request->validate(['tab' => ['nullable', Rule::in(self::TABS)]]);

        $counts = collect(self::TABS)->mapWithKeys(fn (string $tab) => [
            $tab => $currentTeam->quizzes()->inState($tab)->count(),
        ]);

        // Default to the first tab with something in it (open quizzes first).
        $tab = $request->input('tab')
            ?? collect(['open', 'upcoming', 'draft', 'closed'])->first(fn (string $tab) => $counts[$tab] > 0, 'open');

        $quizzes = $currentTeam->quizzes()
            ->inState($tab)
            ->withCount([
                'assessmentQuestions',
                'participants',
                'attempts',
                'attempts as submitted_count' => fn ($query) => $query->where('attempts.status', '!=', AttemptStatus::InProgress),
            ])
            ->withSum('assessmentQuestions', 'marks')
            ->orderByRaw($tab === 'upcoming' ? 'opens_at asc' : ($tab === 'open' ? 'closes_at asc' : 'updated_at desc'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Assessment $quiz) => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'state' => $quiz->state(),
                'access_mode' => $quiz->access_mode->value,
                'opens_at' => $quiz->opens_at?->toIso8601String(),
                'closes_at' => $quiz->closes_at->toIso8601String(),
                'duration_minutes' => $quiz->duration_minutes,
                'questions_count' => $quiz->assessment_questions_count,
                'max_score' => (float) $quiz->assessment_questions_sum_marks,
                'participants_count' => $quiz->participants_count,
                'started_count' => $quiz->attempts_count,
                'submitted_count' => $quiz->submitted_count,
            ]);

        return Inertia::render('quizzes/Index', [
            'quizzes' => $quizzes,
            'tab' => $tab,
            'counts' => $counts,
        ]);
    }

    public function create(Team $currentTeam): Response
    {
        return Inertia::render('quizzes/Settings', [
            'form' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(QuizRequest $request, Team $currentTeam, SaveQuiz $save): RedirectResponse
    {
        $quiz = $save->handle($currentTeam, $request->user(), $request->quizAttributes());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quiz created. Now add some questions.')]);

        return to_route('quizzes.questions.index', [$currentTeam, $quiz]);
    }

    public function edit(Team $currentTeam, Assessment $quiz): Response
    {
        $timezone = $currentTeam->timezone;

        return Inertia::render('quizzes/Settings', [
            ...$this->quizShell($currentTeam, $quiz),
            'form' => [
                'title' => $quiz->title,
                'instructions' => $quiz->instructions ?? '',
                'opens_at' => $quiz->opens_at?->setTimezone($timezone)->format('Y-m-d\TH:i') ?? '',
                'closes_at' => $quiz->closes_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'duration_minutes' => $quiz->duration_minutes ?? '',
                'shuffle_questions' => $quiz->shuffle_questions,
                'shuffle_options' => $quiz->shuffle_options,
                'show_answers_after_release' => $quiz->show_answers_after_release,
                'track_focus' => $quiz->track_focus,
                'one_way_navigation' => $quiz->one_way_navigation,
                'require_fullscreen' => $quiz->require_fullscreen,
                'release_mode' => $quiz->release_mode->value,
                'auto_publish_threshold' => $quiz->auto_publish_threshold !== null ? (float) $quiz->auto_publish_threshold : '',
                'access_mode' => $quiz->access_mode->value,
            ],
            'lockedFields' => $quiz->hasAttempts() ? QuizRequest::LOCKED_ONCE_STARTED : [],
            'accessModeLocked' => ! $quiz->isDraft() || $quiz->participants()->exists(),
            ...$this->formOptions(),
        ]);
    }

    public function update(QuizRequest $request, Team $currentTeam, Assessment $quiz, SaveQuiz $save): RedirectResponse
    {
        $save->handle($currentTeam, $request->user(), $request->quizAttributes(), $quiz);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    public function destroy(Team $currentTeam, Assessment $quiz): RedirectResponse
    {
        Gate::authorize('delete', $quiz);

        $quiz->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quiz deleted.')]);

        return to_route('quizzes.index', $currentTeam);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'releaseModes' => ReleaseMode::options(),
            'accessModes' => AccessMode::options(),
            'defaultThreshold' => (float) config('evalyst.ai.auto_publish_threshold'),
        ];
    }
}
