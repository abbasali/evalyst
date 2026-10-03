<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Independently answers generated choice questions (without the answer key) so
 * disagreements with the key can be flagged. See docs/03-ai.md §2.
 */
class QuestionVerifier extends StructuredAgent
{
    protected function promptFile(): string
    {
        return 'question-verifier';
    }

    /**
     * The user message: each choice draft with options but never `is_correct`.
     *
     * @param  array<int, array{type: string, body: string, options: list<array{body: string}>}>  $drafts  Keyed by draft index.
     */
    public static function buildPrompt(array $drafts): string
    {
        return collect($drafts)->map(function (array $draft, int $index) {
            $kind = $draft['type'] === 'multiple_choice' ? 'select ALL correct options' : 'select exactly ONE option';
            $options = collect($draft['options'])
                ->map(fn (array $option, int $i) => "  [{$i}] {$option['body']}")
                ->implode("\n");

            return "### Question index {$index} ({$kind})\n{$draft['body']}\n\nOptions:\n{$options}";
        })->implode("\n\n---\n\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items($schema->object(fn (JsonSchema $schema) => [
                'index' => $schema->integer()->required(),
                'selected' => $schema->array()->items($schema->integer())->required(),
                'confidence' => $schema->number()->min(0)->max(1)->required(),
                'reasoning' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
