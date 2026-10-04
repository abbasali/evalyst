<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AiRunPurpose;
use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Answer;
use App\Models\QuestionGeneration;
use App\Models\Submission;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the course has spent on AI: totals, by purpose, failures and the most expensive runs.
 */
class AiUsageController extends Controller
{
    public function __invoke(Team $currentTeam): Response
    {
        $month = now($currentTeam->timezone)->startOfMonth()->utc();

        return Inertia::render('courses/AiUsage', [
            'model' => config('evalyst.ai.model'),
            'month' => $this->summary($currentTeam->aiRuns()->where('created_at', '>=', $month)),
            'allTime' => $this->summary($currentTeam->aiRuns()),
            'expensive' => $currentTeam->aiRuns()
                ->orderByDesc('cost_usd')
                ->limit(10)
                ->get()
                ->map(fn (AiRun $run) => [
                    'id' => $run->id,
                    'purpose' => $run->purpose->label(),
                    'subject' => $this->subjectLabel($run),
                    'model' => $run->model,
                    'tokens' => $run->input_tokens + $run->output_tokens,
                    'cost' => (float) $run->cost_usd,
                    'succeeded' => $run->succeeded,
                    'created_at' => $run->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * @param  HasMany<AiRun, Team>  $runs
     * @return array<string, mixed>
     */
    private function summary(HasMany $runs): array
    {
        $byPurpose = $runs->clone()
            ->toBase()
            ->selectRaw('purpose, count(*) as runs, sum(input_tokens + output_tokens) as tokens, sum(cost_usd) as cost, sum(case when succeeded then 0 else 1 end) as failures')
            ->groupBy('purpose')
            ->get()
            ->keyBy('purpose');

        return [
            'cost' => round((float) $byPurpose->sum('cost'), 4),
            'runs' => (int) $byPurpose->sum('runs'),
            'failures' => (int) $byPurpose->sum('failures'),
            'purposes' => array_map(fn (AiRunPurpose $purpose) => [
                'purpose' => $purpose->label(),
                'runs' => (int) ($byPurpose->get($purpose->value)->runs ?? 0),
                'tokens' => (int) ($byPurpose->get($purpose->value)->tokens ?? 0),
                'cost' => round((float) ($byPurpose->get($purpose->value)->cost ?? 0), 4),
            ], AiRunPurpose::cases()),
        ];
    }

    private function subjectLabel(AiRun $run): string
    {
        return match ($run->subject_type) {
            (new Answer)->getMorphClass() => __('Answer #:id', ['id' => $run->subject_id]),
            (new Submission)->getMorphClass() => __('Submission #:id', ['id' => $run->subject_id]),
            (new QuestionGeneration)->getMorphClass() => __('Question generation #:id', ['id' => $run->subject_id]),
            default => (string) $run->subject_type,
        };
    }
}
