<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Questions\AcceptGeneratedQuestions;
use App\Enums\ChoiceScoringPolicy;
use App\Enums\CodeLanguage;
use App\Enums\Difficulty;
use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StoreQuestionGenerationRequest;
use App\Jobs\GenerateQuestions;
use App\Models\QuestionGeneration;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class QuestionGenerationController extends Controller
{
    /**
     * The generation form plus recent history.
     */
    public function create(Team $currentTeam): Response
    {
        return Inertia::render('questions/Generate', [
            'tags' => $currentTeam->tags()->orderBy('name')->get(['id', 'name']),
            'difficulties' => Difficulty::options(),
            'maxQuestions' => (int) config('evalyst.ai.max_generation_questions'),
            'history' => $currentTeam->questionGenerations()
                ->with('user:id,name')
                ->withSum('aiRuns as cost_usd', 'cost_usd')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (QuestionGeneration $generation) => [
                    'id' => $generation->id,
                    'prompt' => Str::limit($generation->prompt, 90),
                    'requested' => $generation->requestedTotal(),
                    'generated' => $generation->status === GenerationStatus::Completed ? count($generation->drafts ?? []) : 0,
                    'accepted' => $generation->accepted_count,
                    'status' => $generation->status->value,
                    'cost_usd' => round((float) $generation->getAttribute('cost_usd'), 4),
                    'user' => $generation->user?->name,
                    'created_at' => $generation->created_at?->toISOString(),
                ]),
        ]);
    }

    public function store(StoreQuestionGenerationRequest $request, Team $currentTeam): RedirectResponse
    {
        $generation = $currentTeam->questionGenerations()->create([
            ...$request->safe()->only(['prompt', 'difficulty', 'include_code_output']),
            'type_counts' => array_map('intval', $request->validated('type_counts')),
            'tag_ids' => array_map('intval', $request->validated('tag_ids', [])),
            'user_id' => $request->user()->id,
            'status' => GenerationStatus::Pending,
        ]);

        GenerateQuestions::dispatch($generation);

        return to_route('question-generations.show', [$currentTeam, $generation]);
    }

    public function show(Team $currentTeam, QuestionGeneration $questionGeneration): Response
    {
        $generation = $questionGeneration;

        return Inertia::render('questions/GenerationShow', [
            'generation' => [
                'id' => $generation->id,
                'prompt' => $generation->prompt,
                'status' => $generation->status->value,
                'stale' => $generation->isStale(),
                'type_counts' => $generation->type_counts,
                'requested' => $generation->requestedTotal(),
                'difficulty' => $generation->difficulty,
                'warnings' => $generation->warnings ?? [],
                'error' => $generation->error,
                'accepted_count' => $generation->accepted_count,
                'cost_usd' => round((float) $generation->aiRuns()->sum('cost_usd'), 4),
                'tags' => Tag::whereKey($generation->tag_ids ?? [])->pluck('name'),
                'created_at' => $generation->created_at?->toISOString(),
            ],
            // While running, `drafts` holds an unverified checkpoint without uids; only show finished drafts.
            'drafts' => collect($generation->status === GenerationStatus::Completed ? $generation->drafts ?? [] : [])->map(fn (array $draft) => [
                ...AcceptGeneratedQuestions::toFormFields($draft),
                'is_code_output' => $draft['is_code_output'] ?? false,
                'verification' => $draft['verification'] ?? ['status' => 'not_applicable'],
                'accepted' => $draft['accepted'] ?? false,
            ])->values(),
            'scoringPolicies' => ChoiceScoringPolicy::options(),
            'codeLanguages' => CodeLanguage::options(),
            'difficulties' => Difficulty::options(),
        ]);
    }

    public function accept(Request $request, Team $currentTeam, QuestionGeneration $questionGeneration, AcceptGeneratedQuestions $accept): RedirectResponse
    {
        /** @var list<array<string, mixed>> $drafts */
        $drafts = array_values(array_filter((array) $request->input('drafts', []), 'is_array'));

        $count = $accept->handle($questionGeneration, $request->user(), $drafts);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count question added to the bank.|:count questions added to the bank.', $count)]);

        return back();
    }

    public function retry(Team $currentTeam, QuestionGeneration $questionGeneration): RedirectResponse
    {
        abort_unless(
            $questionGeneration->status === GenerationStatus::Failed || $questionGeneration->isStale(),
            422,
            __('Only failed or stuck generations can be retried.'),
        );

        $questionGeneration->update(['status' => GenerationStatus::Pending, 'error' => null]);

        GenerateQuestions::dispatch($questionGeneration);

        return to_route('question-generations.show', [$currentTeam, $questionGeneration]);
    }
}
