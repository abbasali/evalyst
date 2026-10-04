<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\AddAssessmentQuestions;
use App\Actions\Assessments\RemoveAssessmentQuestion;
use App\Actions\Assessments\ReorderAssessmentQuestions;
use App\Actions\Assessments\UpdateQuestionMarks;
use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsQuiz;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\Question;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuizQuestionController extends Controller
{
    use PresentsQuiz;

    public function index(Team $currentTeam, Assessment $quiz): Response
    {
        $items = $quiz->assessmentQuestions()->with('question.tags:id,name')->get();

        return Inertia::render('quizzes/Questions', [
            ...$this->quizShell($currentTeam, $quiz),
            'items' => $items->map(fn (AssessmentQuestion $item) => [
                'id' => $item->id,
                'position' => $item->position,
                'marks' => (float) $item->marks,
                'question' => [
                    'id' => $item->question->id,
                    'type' => $item->question->type->value,
                    'type_label' => $item->question->type->label(),
                    'excerpt' => Str::limit($item->question->body, 400, ''),
                    'default_marks' => (float) $item->question->default_marks,
                    'difficulty' => $item->question->difficulty?->value,
                    'needs_verification' => $item->question->needs_verification,
                    'deleted' => $item->question->trashed(),
                    'tags' => $item->question->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name]),
                ],
            ]),
            'types' => QuestionType::options(),
            'difficulties' => Difficulty::options(),
            'tags' => $currentTeam->tags()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The bank browser in the "Add questions" panel (JSON).
     */
    public function bank(Request $request, Team $currentTeam, Assessment $quiz): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::enum(QuestionType::class)],
            'difficulty' => ['nullable', Rule::enum(Difficulty::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer'],
        ]);

        $added = $quiz->assessmentQuestions()->pluck('question_id')->all();

        $questions = $currentTeam->questions()
            ->filter($filters)
            ->with('tags:id,name')
            ->latest('id')
            ->paginate(15)
            ->through(fn (Question $question) => [
                'id' => $question->id,
                'type' => $question->type->value,
                'type_label' => $question->type->label(),
                'excerpt' => Str::limit($question->body, 400, ''),
                'default_marks' => (float) $question->default_marks,
                'difficulty' => $question->difficulty?->value,
                'needs_verification' => $question->needs_verification,
                'tags' => $question->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name]),
                'added' => in_array($question->id, $added, true),
            ]);

        return response()->json($questions);
    }

    public function store(Request $request, Team $currentTeam, Assessment $quiz, AddAssessmentQuestions $add): RedirectResponse
    {
        Gate::authorize('editQuestions', $quiz);

        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1', 'max:200'],
            'question_ids.*' => [
                'integer',
                Rule::exists('questions', 'id')->where('team_id', $currentTeam->id)->whereNull('deleted_at'),
            ],
        ]);

        $count = $add->handle($quiz, array_values(array_map('intval', $data['question_ids'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count question added.|:count questions added.', $count)]);

        return back();
    }

    public function order(Request $request, Team $currentTeam, Assessment $quiz, ReorderAssessmentQuestions $reorder): RedirectResponse
    {
        Gate::authorize('editQuestions', $quiz);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $reorder->handle($quiz, array_values(array_map('intval', $data['ids'])));

        return back();
    }

    public function update(Request $request, Team $currentTeam, Assessment $quiz, AssessmentQuestion $assessmentQuestion, UpdateQuestionMarks $updateMarks): RedirectResponse
    {
        Gate::authorize('editQuestions', $quiz);

        $data = $request->validate([
            'marks' => ['required', 'numeric', 'min:0.5', 'max:100', 'multiple_of:0.5'],
        ]);

        $updateMarks->handle($assessmentQuestion, (float) $data['marks']);

        return back();
    }

    public function destroy(Team $currentTeam, Assessment $quiz, AssessmentQuestion $assessmentQuestion, RemoveAssessmentQuestion $remove): RedirectResponse
    {
        Gate::authorize('editQuestions', $quiz);

        $remove->handle($assessmentQuestion);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question removed from the quiz.')]);

        return back();
    }
}
