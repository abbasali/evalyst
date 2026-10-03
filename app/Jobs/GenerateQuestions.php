<?php

namespace App\Jobs;

use App\Ai\Agents\QuestionGenerator;
use App\Ai\Agents\QuestionVerifier;
use App\Ai\RecordsAiRun;
use App\Ai\Validation\GeneratedQuestionValidator;
use App\Ai\Validation\VerificationComparator;
use App\Enums\AiRunPurpose;
use App\Enums\GenerationStatus;
use App\Models\Question;
use App\Models\QuestionGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generate draft questions, re-ask once for missing counts, verify choice answer keys.
 * See docs/03-ai.md and docs/features/ai-question-generation.md.
 */
class GenerateQuestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public QuestionGeneration $generation)
    {
        $this->onQueue('ai');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('openai')];
    }

    public function handle(RecordsAiRun $runs, GeneratedQuestionValidator $validator, VerificationComparator $comparator): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinished()) {
            return;
        }

        $generation->update(['status' => GenerationStatus::Running, 'error' => null]);

        $requested = array_map('intval', $generation->type_counts);
        $result = $validator->validate($this->generate($runs, $generation, $requested), $requested);
        $drafts = $result['drafts'];
        $warnings = $result['warnings'];

        // One follow-up call asking only for what's missing.
        if ($result['missing'] !== []) {
            $retry = $validator->validate($this->generate($runs, $generation, $result['missing'], $drafts), $result['missing']);
            $drafts = [...$drafts, ...$retry['drafts']];
            $warnings = [...$warnings, ...$retry['warnings']];
        }

        if (count($drafts) < $generation->requestedTotal()) {
            array_unshift($warnings, sprintf('Only %d of %d questions could be generated.', count($drafts), $generation->requestedTotal()));
        }

        $drafts = $this->verify($runs, $comparator, $generation, $drafts);

        $generation->update([
            'status' => GenerationStatus::Completed,
            'drafts' => array_map(fn (array $draft) => [...$draft, 'uid' => (string) Str::ulid(), 'accepted' => false], $drafts),
            'warnings' => $warnings,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->generation->update([
            'status' => GenerationStatus::Failed,
            'error' => $exception ? Str::limit($exception->getMessage(), 1000) : 'Generation failed.',
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $alreadyGenerated
     * @return array<int, mixed>
     */
    private function generate(RecordsAiRun $runs, QuestionGeneration $generation, array $counts, array $alreadyGenerated = []): array
    {
        $existing = [
            ...$this->existingQuestionSummaries($generation),
            ...array_map(fn (array $draft) => Str::limit($this->plain($draft['body']), 100), $alreadyGenerated),
        ];

        $agent = new QuestionGenerator(
            courseName: $generation->team->name,
            typeCounts: array_filter($counts),
            difficulty: $generation->difficulty,
            includeCodeOutput: $generation->include_code_output,
            existingQuestions: $existing,
        );

        $response = $runs->run(AiRunPurpose::QuestionGeneration, $generation, fn () => $agent->prompt($generation->prompt));

        return (array) ($response['questions'] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $drafts
     * @return list<array<string, mixed>>
     */
    private function verify(RecordsAiRun $runs, VerificationComparator $comparator, QuestionGeneration $generation, array $drafts): array
    {
        $choice = array_filter($drafts, fn (array $draft) => in_array($draft['type'], ['single_choice', 'multiple_choice'], true));

        if ($choice === []) {
            return $comparator->compare($drafts, []);
        }

        $response = $runs->run(
            AiRunPurpose::QuestionVerification,
            $generation,
            fn () => (new QuestionVerifier)->prompt(QuestionVerifier::buildPrompt($choice)),
        );

        return $comparator->compare($drafts, (array) ($response['results'] ?? []));
    }

    /**
     * Up to 40 one-line summaries of existing questions (same tags, else newest).
     *
     * @return list<string>
     */
    private function existingQuestionSummaries(QuestionGeneration $generation): array
    {
        return $generation->team->questions()
            ->when($generation->tag_ids, fn ($query, array $tagIds) => $query->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds)))
            ->latest('id')
            ->limit(40)
            ->pluck('body')
            ->map(fn (string $body) => Str::limit($this->plain($body), 100))
            ->all();
    }

    private function plain(string $markdown): string
    {
        return Str::squish(preg_replace('/```.*?```/s', '[code]', $markdown) ?? $markdown);
    }
}
