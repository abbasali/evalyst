<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;

/**
 * Generates draft questions for the bank. See docs/03-ai.md §1.
 */
#[Strict]
#[Timeout(150)]
class QuestionGenerator extends StructuredAgent
{
    /**
     * @param  array<string, int>  $typeCounts
     * @param  list<string>  $existingQuestions  One-line summaries to avoid duplicating.
     */
    public function __construct(
        public string $courseName,
        public array $typeCounts,
        public string $difficulty,
        public bool $includeCodeOutput,
        public array $existingQuestions = [],
    ) {}

    protected function promptFile(): string
    {
        return 'question-generator';
    }

    protected function promptVariables(): array
    {
        return [
            'course' => $this->courseName,
            'counts' => self::describeCounts($this->typeCounts),
            'total' => array_sum($this->typeCounts),
            'difficulty' => $this->difficulty === 'mixed'
                ? 'a mix of easy, medium and hard'
                : $this->difficulty,
            'code_output' => $this->includeCodeOutput
                ? 'Include some "what does this code output?" choice questions: show a short, complete, deterministic snippet and ask for its exact output.'
                : 'Do not ask "what does this code output?" questions.',
            'existing' => $this->existingQuestions === []
                ? '(none yet)'
                : implode("\n", array_map(fn (string $line) => "- {$line}", $this->existingQuestions)),
        ];
    }

    /**
     * @param  array<string, int>  $typeCounts
     */
    public static function describeCounts(array $typeCounts): string
    {
        $labels = [
            'single_choice' => 'single_choice (one correct option)',
            'multiple_choice' => 'multiple_choice (two or more correct options)',
            'open_text' => 'open_text (written answer)',
            'open_code' => 'open_code (written explanation plus code)',
        ];

        return collect($typeCounts)
            ->filter()
            ->map(fn (int $count, string $type) => "- {$count} × {$labels[$type]}")
            ->implode("\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'type' => $schema->string()->enum(['single_choice', 'multiple_choice', 'open_text', 'open_code'])->required(),
                'body' => $schema->string()->required(),
                'code_language' => $schema->string()->nullable()->required(),
                'options' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                    'body' => $schema->string()->required(),
                    'is_correct' => $schema->boolean()->required(),
                ]))->required(),
                'model_answer' => $schema->string()->nullable()->required(),
                'rubric' => $schema->string()->nullable()->required(),
                'explanation' => $schema->string()->required(),
                'difficulty' => $schema->string()->enum(['easy', 'medium', 'hard'])->required(),
                'suggested_marks' => $schema->number()->required(),
                'is_code_output' => $schema->boolean()->required(),
            ]))->required(),
        ];
    }
}
