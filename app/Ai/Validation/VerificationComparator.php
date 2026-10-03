<?php

namespace App\Ai\Validation;

/**
 * Compares the verifier's independent answers with each draft's answer key (pure).
 */
class VerificationComparator
{
    public const MIN_CONFIDENCE = 0.7;

    /**
     * @param  list<array<string, mixed>>  $drafts
     * @param  array<int, mixed>  $results  Raw verifier `results`.
     * @param  string|null  $missingReason  Reasoning for choice drafts without a verifier answer.
     * @return list<array<string, mixed>> Drafts with a `verification` block.
     */
    public function compare(array $drafts, array $results, ?string $missingReason = null): array
    {
        $byIndex = collect($results)
            ->filter(fn ($result) => is_array($result) && isset($result['index']))
            ->keyBy(fn (array $result) => (int) $result['index']);

        return array_map(function (array $draft, int $index) use ($byIndex, $missingReason) {
            if (! in_array($draft['type'], ['single_choice', 'multiple_choice'], true)) {
                return [...$draft, 'verification' => ['status' => 'not_applicable']];
            }

            $result = $byIndex->get($index);

            if (! $result) {
                return [...$draft, 'verification' => [
                    'status' => 'disputed',
                    'verifier_selected' => [],
                    'confidence' => 0,
                    'reasoning' => $missingReason ?? 'The verifier did not return an answer for this question.',
                ]];
            }

            $key = array_keys(array_filter(array_column($draft['options'], 'is_correct')));
            $selected = array_values(array_unique(array_map('intval', (array) ($result['selected'] ?? []))));
            sort($selected);

            $confidence = (float) ($result['confidence'] ?? 0);
            $agrees = $selected === $key && $confidence >= self::MIN_CONFIDENCE;

            return [...$draft, 'verification' => [
                'status' => $agrees ? 'agreed' : 'disputed',
                'verifier_selected' => $selected,
                'confidence' => round($confidence, 2),
                'reasoning' => (string) ($result['reasoning'] ?? ''),
            ]];
        }, $drafts, array_keys($drafts));
    }
}
