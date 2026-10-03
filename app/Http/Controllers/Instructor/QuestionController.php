<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Questions\DuplicateQuestion;
use App\Actions\Questions\SaveQuestion;
use App\Enums\ChoiceScoringPolicy;
use App\Enums\CodeLanguage;
use App\Enums\Difficulty;
use App\Enums\QuestionSource;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\QuestionRequest;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuestionController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::enum(QuestionType::class)],
            'difficulty' => ['nullable', Rule::enum(Difficulty::class)],
            'source' => ['nullable', Rule::enum(QuestionSource::class)],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer'],
            'needs_verification' => ['nullable', 'boolean'],
            'trashed' => ['nullable', 'boolean'],
            'generation' => ['nullable', 'integer'],
        ]);

        $questions = $currentTeam->questions()
            ->filter($filters)
            ->with('tags:id,name')
            ->withCount('options')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Question $question) => [
                'id' => $question->id,
                'type' => $question->type->value,
                'type_label' => $question->type->label(),
                'excerpt' => Str::limit($question->body, 400, ''),
                'question_generation_id' => $question->question_generation_id,
                'default_marks' => (float) $question->default_marks,
                'difficulty' => $question->difficulty?->value,
                'source' => $question->source->value,
                'needs_verification' => $question->needs_verification,
                'locked' => $question->isLocked(),
                'deleted' => $question->trashed(),
                'tags' => $question->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name]),
            ]);

        return Inertia::render('questions/Index', [
            'questions' => $questions,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'type' => $filters['type'] ?? null,
                'difficulty' => $filters['difficulty'] ?? null,
                'source' => $filters['source'] ?? null,
                'tags' => array_map('intval', $filters['tags'] ?? []),
                'needs_verification' => (bool) ($filters['needs_verification'] ?? false),
                'trashed' => (bool) ($filters['trashed'] ?? false),
                'generation' => isset($filters['generation']) ? (int) $filters['generation'] : null,
            ],
            'total' => $currentTeam->questions()->withTrashed()->count(),
            ...$this->formOptions($currentTeam),
        ]);
    }

    public function create(Team $currentTeam): Response
    {
        return Inertia::render('questions/Form', [
            'question' => null,
            ...$this->formOptions($currentTeam),
        ]);
    }

    public function store(QuestionRequest $request, Team $currentTeam, SaveQuestion $save): RedirectResponse
    {
        $save->handle($currentTeam, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question saved.')]);

        return $request->boolean('add_another')
            ? to_route('questions.create', $currentTeam)
            : to_route('questions.index', $currentTeam);
    }

    /**
     * Full detail as JSON (used by the preview dialog).
     */
    public function show(Team $currentTeam, Question $question): QuestionResource
    {
        return new QuestionResource($question->load(['options', 'tags']));
    }

    public function edit(Team $currentTeam, Question $question): Response
    {
        return Inertia::render('questions/Form', [
            'question' => (new QuestionResource($question->load(['options', 'tags'])))->resolve(),
            ...$this->formOptions($currentTeam),
        ]);
    }

    public function update(QuestionRequest $request, Team $currentTeam, Question $question, SaveQuestion $save): RedirectResponse
    {
        $save->handle($currentTeam, $request->user(), $request->validated(), $question);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question updated.')]);

        return to_route('questions.index', $currentTeam);
    }

    public function destroy(Team $currentTeam, Question $question): RedirectResponse
    {
        // From M05: blocked while the question is in a published assessment.
        $question->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question deleted.')]);

        return back();
    }

    public function restore(Team $currentTeam, Question $question): RedirectResponse
    {
        $question->restore();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question restored.')]);

        return back();
    }

    public function duplicate(Request $request, Team $currentTeam, Question $question, DuplicateQuestion $duplicate): RedirectResponse
    {
        $copy = $duplicate->handle($question->load(['options', 'tags']), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question duplicated. You are editing the copy.')]);

        return to_route('questions.edit', [$currentTeam, $copy]);
    }

    /**
     * Clear the "needs verification" flag after the instructor checked the answer key.
     */
    public function verify(Team $currentTeam, Question $question): RedirectResponse
    {
        $question->update(['needs_verification' => false]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as verified.')]);

        return back();
    }

    /**
     * Bulk add/remove a tag, or delete, for selected questions.
     */
    public function bulk(Request $request, Team $currentTeam): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['add_tag', 'remove_tag', 'delete'])],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'tag_id' => ['required_unless:action,delete', 'nullable', 'integer', Rule::exists('tags', 'id')->where('team_id', $currentTeam->id)],
        ]);

        $ids = $currentTeam->questions()->whereKey($data['ids'])->pluck('id');

        $count = DB::transaction(fn () => match ($request->string('action')->value()) {
            // Questions already at the 10-tag limit are skipped.
            'add_tag' => DB::table('question_tag')->insertOrIgnore(
                $currentTeam->questions()->whereKey($ids)->has('tags', '<', 10)->pluck('id')
                    ->map(fn (int $id) => ['question_id' => $id, 'tag_id' => $data['tag_id']])->all(),
            ),
            'remove_tag' => DB::table('question_tag')->whereIn('question_id', $ids)->where('tag_id', $data['tag_id'])->delete(),
            default => $currentTeam->questions()->whereKey($ids)->get()->each->delete()->count(),
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count question updated.|:count questions updated.', $count)]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Team $team): array
    {
        return [
            'types' => QuestionType::options(),
            'difficulties' => Difficulty::options(),
            'scoringPolicies' => ChoiceScoringPolicy::options(),
            'codeLanguages' => CodeLanguage::options(),
            'tags' => $team->tags()->withCount('questions')->orderBy('name')->get()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'questions_count' => $tag->questions_count]),
        ];
    }
}
