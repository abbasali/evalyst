<?php

namespace App\Jobs;

use App\Ai\Agents\QuestionGenerator;
use App\Ai\Agents\QuestionVerifier;
use App\Ai\RecordsAiRun;
use App\Ai\Validation\GeneratedQuestionValidator;
use App\Ai\Validation\VerificationComparator;
use App\Enums\AiRunPurpose;
use App\Enums\GenerationStatus;
use App\Models\QuestionGeneration;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Generate draft questions, re-ask once for missing counts, verify choice answer keys.
 * See docs/03-ai.md and docs/features/ai-question-generation.md.
 */
class GenerateQuestions implements ShouldQueue
{
    use Queueable;

    /**
     * Real failures allowed (rate-limit releases don't count towards this).
     */
    public int $maxExceptions = 2;

    /**
     * Generation (≤150s) + one follow-up (≤150s) + verification (≤90s) can't all be slow
     * at once in practice; keep below the queue's retry_after (360s).
     */
    public int $timeout = 330;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public QuestionGeneration $generation)
    {
        $this->onQueue(config('evalyst.ai.queue'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("question-generation:{$this->generation->id}"))->expireAfter(400)->releaseAfter(30),
            new RateLimited('openai'),
        ];
    }

    public function handle(RecordsAiRun $runs, GeneratedQuestionValidator $validator, VerificationComparator $comparator): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinished()) {
            return;
        }

        $generation->update(['status' => GenerationStatus::Running, 'error' => null]);

        // Checkpoint: a retried job reuses drafts saved by an earlier attempt instead of paying again.
        if ($generation->drafts === null) {
            [$drafts, $warnings] = $this->generateDrafts($runs, $validator, $generation);
            $generation->update(['drafts' => $drafts, 'warnings' => $warnings]);
        }

        [$drafts, $verifyWarning] = $this->verify($runs, $comparator, $generation, $generation->drafts ?? []);

        // Never overwrite a generation another run already completed (drafts may have been accepted).
        QuestionGeneration::whereKey($generation->id)
            ->where('status', GenerationStatus::Running)
            ->update([
                'status' => GenerationStatus::Completed,
                'drafts' => json_encode(array_map(fn (array $draft) => [...$draft, 'uid' => (string) Str::ulid(), 'accepted' => false], $drafts)),
                'warnings' => json_encode(array_values(array_filter([...($generation->warnings ?? []), $verifyWarning]))),
                'updated_at' => now(),
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->generation->update([
            'status' => GenerationStatus::Failed,
            'error' => __('The AI service could not generate questions right now. Please try again in a few minutes.'),
        ]);
    }

    /**
     * Generate, validate and (once) ask again for missing counts.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function generateDrafts(RecordsAiRun $runs, GeneratedQuestionValidator $validator, QuestionGeneration $generation): array
    {
        $requested = array_map('intval', $generation->type_counts);
        $result = $validator->validate($this->generate($runs, $generation, $requested), $requested);
        $drafts = $result['drafts'];
        $warnings = $result['warnings'];

        if ($result['missing'] !== []) {
            $retry = $validator->validate($this->generate($runs, $generation, $result['missing'], $drafts), $result['missing']);
            $drafts = [...$drafts, ...$retry['drafts']];
            $warnings = [...$warnings, ...$retry['warnings']];
        }

        if (count($drafts) < $generation->requestedTotal()) {
            array_unshift($warnings, sprintf('Only %d of %d questions could be generated.', count($drafts), $generation->requestedTotal()));
        }

        return [$drafts, $warnings];
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

        return (array) (self::structured($response)['questions'] ?? []);
    }

    /**
     * Check choice answer keys. If the verifier is unavailable, flag choice drafts as
     * disputed instead of failing (the instructor reviews them anyway).
     *
     * @param  list<array<string, mixed>>  $drafts
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function verify(RecordsAiRun $runs, VerificationComparator $comparator, QuestionGeneration $generation, array $drafts): array
    {
        $choice = array_filter($drafts, fn (array $draft) => in_array($draft['type'], ['single_choice', 'multiple_choice'], true));

        if ($choice === []) {
            return [$comparator->compare($drafts, []), null];
        }

        try {
            $response = $runs->run(
                AiRunPurpose::QuestionVerification,
                $generation,
                fn () => (new QuestionVerifier)->prompt(QuestionVerifier::buildPrompt($choice)),
            );
        } catch (Throwable $exception) {
            report($exception);

            return [
                $comparator->compare($drafts, [], __('The answer key could not be checked automatically.')),
                __('Answer keys could not be checked automatically; please review the choice questions carefully.'),
            ];
        }

        return [$comparator->compare($drafts, (array) (self::structured($response)['results'] ?? [])), null];
    }

    /**
     * Up to 40 one-line summaries of existing questions (same tags, else newest).
     *
     * @return array<int, string>
     */
    private function existingQuestionSummaries(QuestionGeneration $generation): array
    {
        $tagged = $generation->team->questions()
            ->when($generation->tag_ids, fn ($query, array $tagIds) => $query->whereHas('tags', fn ($query) => $query->whereIn('tags.id', $tagIds)));

        // Same tags, else the newest questions in the course.
        $query = $generation->tag_ids && $tagged->clone()->exists() ? $tagged : $generation->team->questions();

        return $query
            ->latest('id')
            ->limit(40)
            ->pluck('body')
            ->map(fn (string $body) => Str::limit($this->plain($body), 100))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function structured(AgentResponse $response): array
    {
        return $response instanceof Arrayable ? $response->toArray() : [];
    }

    private function plain(string $markdown): string
    {
        return Str::squish(preg_replace('/```.*?```/s', '[code]', $markdown) ?? $markdown);
    }
}
