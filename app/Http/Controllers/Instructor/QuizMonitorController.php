<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Attempts\ManageAttempt;
use App\Enums\AccessMode;
use App\Enums\AnswerGradingStatus;
use App\Enums\AttemptEventType;
use App\Enums\AttemptStatus;
use App\Grading\ChoiceScorer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsQuiz;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Attempt;
use App\Models\Participant;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The live monitor tab: who has started, progress, time left, and suspicious activity.
 */
class QuizMonitorController extends Controller
{
    use PresentsQuiz;

    /** Highlight a student from this many focus losses / pastes / fullscreen exits. */
    public const ALERT_AT = 3;

    private const STATUS_ORDER = ['in_progress', 'submitted', 'not_started'];

    public function show(Team $currentTeam, Assessment $quiz): Response
    {
        // Answer keys of choice questions, loaded once, to score students live while they work.
        $items = $quiz->assessmentQuestions()->with('question.options')->get()->keyBy('id')
            ->filter(fn (AssessmentQuestion $item) => $item->question->type->isChoice());

        $participants = $quiz->participants()
            ->with([
                'student',
                // Answer rows only for students still working, and only for choice questions.
                'attempt.answers' => fn ($query) => $query
                    ->select(['id', 'attempt_id', 'assessment_question_id', 'selected_option_ids', 'max_score'])
                    ->whereIn('assessment_question_id', $items->keys())
                    ->whereHas('attempt', fn ($query) => $query->where('status', AttemptStatus::InProgress)),
                'attempt' => fn ($query) => $query->withSum(['answers as final_score' => fn ($query) => $query->where('grading_status', AnswerGradingStatus::Final)], 'score')->withCount([
                    'answers as unsettled_count' => fn ($query) => $query->where('grading_status', '!=', AnswerGradingStatus::Final),
                    'answers as answered_count' => fn ($query) => $query->whereNotNull('answered_at'),
                    'answers as flagged_count' => fn ($query) => $query->where('flagged', true),
                    'events as paste_count' => fn ($query) => $query->where('type', AttemptEventType::Pasted),
                    'events as fullscreen_exit_count' => fn ($query) => $query->where('type', AttemptEventType::FullscreenExited),
                ])])
            ->get();

        // Students taking it now first, then submitted, then not started; by roll number within each.
        $rows = $participants->map(fn (Participant $participant) => $this->row($participant, $items))
            ->sortBy([
                fn (array $a, array $b) => array_search($a['status'], self::STATUS_ORDER, true) <=> array_search($b['status'], self::STATUS_ORDER, true),
                fn (array $a, array $b) => strnatcmp($a['roll_number'], $b['roll_number']),
            ])
            ->values();

        return Inertia::render('quizzes/Monitor', [
            ...$this->quizShell($currentTeam, $quiz),
            'rows' => $rows,
            'summary' => [
                'not_started' => $rows->where('status', 'not_started')->count(),
                'in_progress' => $rows->where('status', 'in_progress')->count(),
                'submitted' => $rows->where('status', 'submitted')->count(),
            ],
            'serverNow' => now()->toIso8601String(),
            'alertAt' => self::ALERT_AT,
            'canAllowResume' => $quiz->access_mode === AccessMode::SharedCode,
        ]);
    }

    public function allowResume(Team $currentTeam, Assessment $quiz, Participant $participant, Request $request, ManageAttempt $manage): RedirectResponse
    {
        if ($quiz->access_mode !== AccessMode::SharedCode) {
            return $this->failed(__('Roster students can already resume from any device with their code.'));
        }

        if (! $participant->attempt?->isInProgress()) {
            return $this->failed(__(':name isn\'t taking the quiz right now.', ['name' => $participant->student->name]));
        }

        $manage->allowResume($request->user(), $participant);

        return $this->done(__(':name can resume from any browser in the next :minutes minutes.', [
            'name' => $participant->student->name,
            'minutes' => ManageAttempt::RESUME_WINDOW_MINUTES,
        ]));
    }

    public function reset(Team $currentTeam, Assessment $quiz, Participant $participant, Request $request, ManageAttempt $manage): RedirectResponse
    {
        if ($participant->attempt === null) {
            return $this->failed(__(':name hasn\'t started.', ['name' => $participant->student->name]));
        }

        // After closing, a reset would delete the submission and the student couldn't start again.
        if (! $quiz->isOpen()) {
            return $this->failed(__('The quiz isn\'t open, so :name couldn\'t start again. Extend the closing time first.', ['name' => $participant->student->name]));
        }

        $request->validate(['roll_number' => ['required', 'string']]);

        if (mb_strtoupper(trim($request->string('roll_number')->value())) !== $participant->student->roll_number) {
            throw ValidationException::withMessages(['roll_number' => __('Type the roll number exactly to confirm.')]);
        }

        $manage->reset($request->user(), $participant);

        return $this->done(__(':name\'s attempt was reset. They can start again.', ['name' => $participant->student->name]));
    }

    public function forceSubmit(Team $currentTeam, Assessment $quiz, Participant $participant, Request $request, ManageAttempt $manage): RedirectResponse
    {
        if (! $participant->attempt?->isInProgress() || ! $manage->forceSubmit($request->user(), $participant)) {
            return $this->failed(__(':name had already submitted.', ['name' => $participant->student->name]));
        }

        return $this->done(__(':name\'s attempt was submitted.', ['name' => $participant->student->name]));
    }

    /**
     * @param  Collection<int, AssessmentQuestion>  $items
     * @return array<string, mixed>
     */
    private function row(Participant $participant, Collection $items): array
    {
        /** @var Attempt|null $attempt */
        $attempt = $participant->attempt;

        return [
            'id' => $participant->id,
            'name' => $participant->student->name,
            'roll_number' => $participant->student->roll_number,
            'status' => match (true) {
                $attempt === null => 'not_started',
                $attempt->isInProgress() => 'in_progress',
                default => 'submitted',
            },
            'started_at' => $attempt?->started_at->toIso8601String(),
            'deadline_at' => $attempt?->deadline_at->toIso8601String(),
            'submitted_at' => $attempt?->submitted_at?->toIso8601String(),
            'auto_submitted' => (bool) $attempt?->auto_submitted,
            'answered' => (int) ($attempt?->getAttribute('answered_count') ?? 0),
            'total' => $attempt ? $attempt->questionCount() : null,
            'flagged' => (int) ($attempt?->getAttribute('flagged_count') ?? 0),
            'focus_lost' => $attempt->focus_lost_count ?? 0,
            'pastes' => (int) ($attempt?->getAttribute('paste_count') ?? 0),
            'fullscreen_exits' => (int) ($attempt?->getAttribute('fullscreen_exit_count') ?? 0),
            'resume_allowed_until' => $attempt?->resume_override_until?->isFuture() ? $attempt->resume_override_until->toIso8601String() : null,
            ...$this->liveScore($attempt, $items),
        ];
    }

    /**
     * The score so far. While a student works, choice answers are scored on the fly (open answers
     * are graded after submitting); afterwards it's the sum of the grades settled so far.
     *
     * @param  Collection<int, AssessmentQuestion>  $items
     * @return array{score: float|null, max_score: float|null, score_state: string|null}
     */
    private function liveScore(?Attempt $attempt, Collection $items): array
    {
        if ($attempt === null) {
            return ['score' => null, 'max_score' => null, 'score_state' => null];
        }

        if (! $attempt->isInProgress()) {
            return [
                'score' => round((float) $attempt->getAttribute('final_score'), 2),
                'max_score' => (float) $attempt->max_score,
                'score_state' => (int) $attempt->getAttribute('unsettled_count') > 0 ? 'grading' : 'final',
            ];
        }

        $scorer = app(ChoiceScorer::class);
        $score = 0.0;

        foreach ($attempt->answers as $answer) {
            $question = $items->get($answer->assessment_question_id)?->question;

            if ($question !== null) {
                $score += $scorer->score(
                    $question->type,
                    $question->scoring_policy,
                    $question->options->where('is_correct', true)->pluck('id')->all(),
                    $answer->selected_option_ids,
                    (float) $answer->max_score,
                );
            }
        }

        return [
            'score' => round($score, 2),
            'max_score' => (float) $attempt->max_score,
            'score_state' => 'live',
        ];
    }

    /**
     * The monitor polls, so actions often race a student; explain instead of erroring.
     */
    private function failed(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
