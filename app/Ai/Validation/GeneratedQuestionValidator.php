<?php

namespace App\Ai\Validation;

use App\Enums\CodeLanguage;
use App\Enums\QuestionType;

/**
 * Checks raw QuestionGenerator output against the bank's rules (pure, no I/O).
 */
class GeneratedQuestionValidator
{
    /**
     * @param  array<int, mixed>  $questions  Raw `questions` array from the model.
     * @param  array<string, int>  $requested  Requested counts per type.
     * @return array{drafts: list<array<string, mixed>>, warnings: list<string>, missing: array<string, int>}
     */
    public function validate(array $questions, array $requested): array
    {
        $drafts = [];
        $warnings = [];
        $counts = array_fill_keys(array_keys($requested), 0);

        foreach (array_values($questions) as $index => $raw) {
            $number = $index + 1;
            $error = is_array($raw) ? $this->error($raw) : 'not an object';

            if ($error !== null) {
                $warnings[] = "Question {$number} was dropped: {$error}.";

                continue;
            }

            $type = QuestionType::from($raw['type']);

            // Never keep more of a type than was asked for.
            if (($counts[$type->value] ?? 0) >= ($requested[$type->value] ?? 0)) {
                $warnings[] = "Question {$number} was dropped: more {$type->label()} questions than requested.";

                continue;
            }

            $counts[$type->value]++;
            $drafts[] = $this->normalise($raw, $type);
        }

        $missing = [];

        foreach ($requested as $type => $count) {
            if ($count > $counts[$type]) {
                $missing[$type] = $count - $counts[$type];
            }
        }

        return ['drafts' => $drafts, 'warnings' => $warnings, 'missing' => $missing];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function error(array $raw): ?string
    {
        $type = QuestionType::tryFrom((string) ($raw['type'] ?? ''));

        if (! $type) {
            return 'unknown question type';
        }

        if (trim((string) ($raw['body'] ?? '')) === '') {
            return 'empty question text';
        }

        if ($type->isChoice()) {
            $options = array_values(array_filter((array) ($raw['options'] ?? []), fn ($option) => is_array($option) && trim((string) ($option['body'] ?? '')) !== ''));
            $bodies = array_map(fn ($option) => mb_strtolower(trim((string) $option['body'])), $options);
            $correct = count(array_filter($options, fn ($option) => (bool) ($option['is_correct'] ?? false)));

            if (count($options) < 2 || count($options) > 8) {
                return 'needs 2–8 options';
            }

            if (count(array_unique($bodies)) !== count($bodies)) {
                return 'duplicate options';
            }

            if ($type === QuestionType::SingleChoice && $correct !== 1) {
                return 'single choice needs exactly one correct option';
            }

            if ($type === QuestionType::MultipleChoice && ($correct < 2 || $correct === count($options))) {
                return 'multiple choice needs two or more correct options and at least one incorrect';
            }

            return null;
        }

        if (trim((string) ($raw['model_answer'] ?? '')) === '') {
            return 'missing model answer';
        }

        if ($type === QuestionType::OpenCode && ! CodeLanguage::tryFrom((string) ($raw['code_language'] ?? ''))) {
            return 'missing or unknown code language';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalise(array $raw, QuestionType $type): array
    {
        $marks = round(max(0.5, min(100, (float) ($raw['suggested_marks'] ?? 1))) * 2) / 2;

        return [
            'type' => $type->value,
            'body' => trim((string) $raw['body']),
            'code_language' => $type === QuestionType::OpenCode ? $raw['code_language'] : null,
            'options' => $type->isChoice()
                ? array_values(array_map(fn ($option) => [
                    'body' => trim((string) $option['body']),
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                ], array_filter((array) $raw['options'], fn ($option) => is_array($option) && trim((string) ($option['body'] ?? '')) !== '')))
                : [],
            'model_answer' => $type->isOpen() ? trim((string) $raw['model_answer']) : null,
            'rubric' => $type->isOpen() && filled($raw['rubric'] ?? null) ? trim((string) $raw['rubric']) : null,
            'explanation' => filled($raw['explanation'] ?? null) ? trim((string) $raw['explanation']) : null,
            'difficulty' => in_array($raw['difficulty'] ?? null, ['easy', 'medium', 'hard'], true) ? $raw['difficulty'] : null,
            'suggested_marks' => $marks,
            'is_code_output' => (bool) ($raw['is_code_output'] ?? false),
        ];
    }
}
