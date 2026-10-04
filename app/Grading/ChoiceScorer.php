<?php

namespace App\Grading;

use App\Enums\ChoiceScoringPolicy;
use App\Enums\QuestionType;

/**
 * Scores a choice answer (pure). See docs/features/grading-and-review.md.
 */
class ChoiceScorer
{
    /**
     * @param  array<int, int>  $correctOptionIds
     * @param  array<int, int>|null  $selectedOptionIds
     */
    public function score(QuestionType $type, ?ChoiceScoringPolicy $policy, array $correctOptionIds, ?array $selectedOptionIds, float $marks): float
    {
        $correct = array_values(array_unique(array_map('intval', $correctOptionIds)));
        $selected = array_values(array_unique(array_map('intval', $selectedOptionIds ?? [])));

        if ($selected === [] || $correct === [] || $marks <= 0) {
            return 0.0;
        }

        $correctSelected = count(array_intersect($selected, $correct));
        $wrongSelected = count(array_diff($selected, $correct));
        $exact = $wrongSelected === 0 && $correctSelected === count($correct);

        if ($type === QuestionType::SingleChoice) {
            return $exact && count($selected) === 1 ? $marks : 0.0;
        }

        $fraction = match ($policy ?? ChoiceScoringPolicy::AllOrNothing) {
            ChoiceScoringPolicy::AllOrNothing => $exact ? 1.0 : 0.0,
            ChoiceScoringPolicy::Partial => $wrongSelected > 0 ? 0.0 : $correctSelected / count($correct),
            ChoiceScoringPolicy::PartialWithPenalty => max(0, ($correctSelected - $wrongSelected) / count($correct)),
        };

        return min($marks, max(0.0, round($marks * $fraction, 2, PHP_ROUND_HALF_UP)));
    }
}
