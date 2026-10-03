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
     * @param  array<int, array<string, mixed>>  $drafts  Choice drafts keyed by draft index (type, body, options).
     */
    public static function buildPrompt(array $drafts): string
    {
        $blocks = [];

        foreach ($drafts as $index => $draft) {
            $kind = $draft['type'] === 'multiple_choice' ? 'select ALL correct options' : 'select exactly ONE option';
            $options = [];

            foreach (array_values((array) $draft['options']) as $i => $option) {
                $options[] = "  [{$i}] {$option['body']}";
            }

            $blocks[] = "### Question index {$index} ({$kind})\n{$draft['body']}\n\nOptions:\n".implode("\n", $options);
        }

        return implode("\n\n---\n\n", $blocks);
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
