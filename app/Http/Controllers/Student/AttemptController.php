<?php

namespace App\Http\Controllers\Student;

use App\Actions\Attempts\SaveAnswer;
use App\Actions\Attempts\SubmitAttempt;
use App\Enums\AttemptEventType;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\ResolvesParticipant;
use App\Models\Answer;
use App\Models\Attempt;
use App\Models\QuestionOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Taking the quiz: one question per screen, autosave, review and submit.
 * Never sends the answer key (is_correct, model answer, rubric, explanation).
 */
class AttemptController extends Controller
{
    use ResolvesParticipant;

    public function show(Request $request, Attempt $attempt, int $position, SubmitAttempt $submit): Response|RedirectResponse
    {
        $submit->expireIfOverdue($attempt);

        if (! $attempt->isInProgress()) {
            return to_route('student.done', $attempt->public_id);
        }

        abort_unless($position >= 1 && $position <= $attempt->questionCount(), 404);

        $assessment = $this->participant($request)->assessment;

        // One-way: only the current question, or the next one via "Next".
        if ($assessment->one_way_navigation && ($position < $attempt->furthest_position || $position > $attempt->furthest_position + 1)) {
            return to_route('student.question', [$attempt->public_id, $attempt->furthest_position]);
        }

        // Opening the next question closes the previous one in one-way mode, so never prefetch these pages.
        if ($position > $attempt->furthest_position) {
            $attempt->update(['furthest_position' => $position]);
        }

        $answers = $this->answersInOrder($attempt);
        /** @var Answer $answer */
        $answer = $answers[$position - 1];
        $question = $answer->question;
        $options = $this->optionsInOrder($attempt, $answer)->values();

        return Inertia::render('student/quiz/Attempt', [
            ...$this->attemptProps($request, $attempt, $answers),
            'position' => $position,
            // Options are identified by their displayed index, so IDs can't reveal the authored order.
            'question' => [
                'key' => $position,
                'type' => $question->type->value,
                'body' => $question->body,
                'code_language' => $question->code_language?->value,
                'options' => $options->map(fn (QuestionOption $option, int $index) => ['id' => $index, 'body' => $option->body])->values(),
            ],
            'answer' => [
                'selected_option_ids' => $options->keys()
                    ->filter(fn (int $index) => in_array($options[$index]->id, $answer->selected_option_ids ?? [], true))
                    ->values(),
                'text_answer' => $answer->text_answer ?? '',
                'code_answer' => $answer->code_answer ?? '',
                'flagged' => $answer->flagged,
                'saved_at' => $answer->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function save(Request $request, Attempt $attempt, int $position, SaveAnswer $save): JsonResponse
    {
        $assessmentQuestionId = $attempt->assessmentQuestionIdAt($position);
        abort_if($assessmentQuestionId === null, 404);

        $answer = $attempt->answers()->where('assessment_question_id', $assessmentQuestionId)->with('question.options')->firstOrFail();
        $type = $answer->question->type;
        $options = $this->optionsInOrder($attempt, $answer)->values();

        $data = $request->validate([
            'selected_option_ids' => ['nullable', 'array', $type === QuestionType::SingleChoice ? 'max:1' : 'max:20'],
            'selected_option_ids.*' => ['integer', 'distinct', Rule::in($options->keys()->all())],
            'text_answer' => ['nullable', 'string', 'max:10000'],
            'code_answer' => ['nullable', 'string', 'max:20000'],
            'flagged' => ['boolean'],
        ]);

        try {
            $save->handle($attempt, $answer, [
                'selected_option_ids' => $type->isChoice()
                    ? array_values(array_map(fn ($index) => $options[(int) $index]->id, $data['selected_option_ids'] ?? []))
                    : null,
                'text_answer' => $type->isOpen() ? ($data['text_answer'] ?? null) : null,
                'code_answer' => $type === QuestionType::OpenCode ? ($data['code_answer'] ?? null) : null,
                'flagged' => (bool) ($data['flagged'] ?? false),
            ]);
        } catch (ConflictHttpException $exception) {
            return response()->json([
                'reason' => $exception->getMessage(),
                'redirect' => $attempt->fresh()?->isInProgress()
                    ? route('student.question', [$attempt->public_id, $attempt->furthest_position])
                    : route('student.done', $attempt->public_id),
            ], 409);
        }

        return response()->json(['saved_at' => $answer->updated_at?->toIso8601String(), 'answered' => $answer->isAnswered()]);
    }

    public function review(Request $request, Attempt $attempt, SubmitAttempt $submit): Response|RedirectResponse
    {
        $submit->expireIfOverdue($attempt);

        if (! $attempt->isInProgress()) {
            return to_route('student.done', $attempt->public_id);
        }

        // One-way quizzes reach the review only from the last question.
        if ($this->participant($request)->assessment->one_way_navigation && $attempt->furthest_position < $attempt->questionCount()) {
            return to_route('student.question', [$attempt->public_id, $attempt->furthest_position]);
        }

        return Inertia::render('student/quiz/Review', $this->attemptProps($request, $attempt, $this->answersInOrder($attempt)));
    }

    public function submit(Request $request, Attempt $attempt, SubmitAttempt $submit): RedirectResponse
    {
        $submit->handle($attempt, $request->boolean('auto') ? AttemptEventType::AutoSubmitted : null);

        return to_route('student.done', $attempt->public_id);
    }

    public function done(Request $request, Attempt $attempt, SubmitAttempt $submit): Response|RedirectResponse
    {
        $submit->expireIfOverdue($attempt);

        if ($attempt->isInProgress()) {
            return to_route('student.question', [$attempt->public_id, $attempt->furthest_position]);
        }

        $participant = $this->participant($request);

        // Back from here must refetch the quiz pages, not show cached editable copies.
        Inertia::clearHistory();

        return Inertia::render('student/quiz/Done', [
            'submittedAt' => $attempt->submitted_at?->toIso8601String(),
            'autoSubmitted' => $attempt->auto_submitted,
            'timezone' => $participant->assessment->team->timezone,
            'resultsUrl' => route('student.results', $participant->public_id),
        ]);
    }

    /**
     * Props shared by the question and review screens (timer, map, anti-cheating settings).
     *
     * @param  Collection<int, Answer>  $answers
     * @return array<string, mixed>
     */
    private function attemptProps(Request $request, Attempt $attempt, Collection $answers): array
    {
        $assessment = $this->participant($request)->assessment;

        return [
            'attempt' => [
                'public_id' => $attempt->public_id,
                'deadline_at' => $attempt->deadline_at->toIso8601String(),
                'server_now' => now()->toIso8601String(),
                'total' => $attempt->questionCount(),
                'furthest_position' => $attempt->furthest_position,
                'one_way_navigation' => $assessment->one_way_navigation,
                'require_fullscreen' => $assessment->require_fullscreen,
                'track_focus' => $assessment->track_focus,
            ],
            'map' => $answers->values()->map(fn (Answer $answer, int $index) => [
                'position' => $index + 1,
                'answered' => $answer->isAnswered(),
                'flagged' => $answer->flagged,
                'saved_at' => $answer->updated_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * @return Collection<int, Answer>
     */
    private function answersInOrder(Attempt $attempt): Collection
    {
        $answers = $attempt->answers()->with('question.options')->get()->keyBy('assessment_question_id');

        return collect($attempt->question_order)->map(fn (int $id) => $answers[$id])->values();
    }

    /**
     * @return Collection<int, QuestionOption>
     */
    private function optionsInOrder(Attempt $attempt, Answer $answer): Collection
    {
        $options = $answer->question->options;
        $order = $attempt->option_order[$answer->assessment_question_id] ?? null;

        return $order === null
            ? $options
            : $options->sortBy(fn (QuestionOption $option) => array_search($option->id, $order, true))->values();
    }
}
