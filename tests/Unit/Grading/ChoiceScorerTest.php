<?php

use App\Enums\ChoiceScoringPolicy as Policy;
use App\Enums\QuestionType as Type;
use App\Grading\ChoiceScorer;

dataset('choice scores', [
    // single choice
    'single correct' => [Type::SingleChoice, null, [1], [1], 2, 2.0],
    'single wrong' => [Type::SingleChoice, null, [1], [2], 2, 0.0],
    'single two picked' => [Type::SingleChoice, null, [1], [1, 2], 2, 0.0],
    'single none' => [Type::SingleChoice, null, [1], [], 2, 0.0],
    'single null' => [Type::SingleChoice, null, [1], null, 2, 0.0],
    'single duplicates' => [Type::SingleChoice, null, [1], [1, 1], 2, 2.0],
    // all or nothing
    'aon exact' => [Type::MultipleChoice, Policy::AllOrNothing, [1, 2], [2, 1], 3, 3.0],
    'aon missing one' => [Type::MultipleChoice, Policy::AllOrNothing, [1, 2], [1], 3, 0.0],
    'aon extra wrong' => [Type::MultipleChoice, Policy::AllOrNothing, [1, 2], [1, 2, 3], 3, 0.0],
    'aon default policy' => [Type::MultipleChoice, null, [1, 2], [1, 2], 3, 3.0],
    // partial
    'partial half' => [Type::MultipleChoice, Policy::Partial, [1, 2], [1], 2, 1.0],
    'partial with wrong' => [Type::MultipleChoice, Policy::Partial, [1, 2], [1, 3], 2, 0.0],
    'partial all selected' => [Type::MultipleChoice, Policy::Partial, [1, 2], [1, 2, 3, 4], 2, 0.0],
    'partial fractional marks' => [Type::MultipleChoice, Policy::Partial, [1, 2, 3], [1], 1.5, 0.5],
    'partial thirds rounding' => [Type::MultipleChoice, Policy::Partial, [1, 2, 3], [1], 1, 0.33],
    'partial two thirds rounding' => [Type::MultipleChoice, Policy::Partial, [1, 2, 3], [1, 2], 1, 0.67],
    'partial unknown id' => [Type::MultipleChoice, Policy::Partial, [1, 2], [1, 99], 2, 0.0],
    'partial none' => [Type::MultipleChoice, Policy::Partial, [1, 2], [], 2, 0.0],
    // partial with penalty
    'penalty exact' => [Type::MultipleChoice, Policy::PartialWithPenalty, [1, 2], [1, 2], 4, 4.0],
    'penalty one right one wrong' => [Type::MultipleChoice, Policy::PartialWithPenalty, [1, 2, 3], [1, 4], 3, 0.0],
    'penalty two right one wrong' => [Type::MultipleChoice, Policy::PartialWithPenalty, [1, 2, 3], [1, 2, 4], 3, 1.0],
    'penalty floors at zero' => [Type::MultipleChoice, Policy::PartialWithPenalty, [1, 2], [3, 4, 5], 2, 0.0],
    'penalty all selected' => [Type::MultipleChoice, Policy::PartialWithPenalty, [1, 2], [1, 2, 3, 4], 2, 0.0],
    'penalty unknown id counts wrong' => [Type::MultipleChoice, Policy::PartialWithPenalty, [1, 2, 3], [1, 2, 3, 99], 3, 2.0],
]);

test('choice answers are scored by type and policy', function (Type $type, ?Policy $policy, array $correct, ?array $selected, float $marks, float $expected) {
    $score = (new ChoiceScorer)->score($type, $policy, $correct, $selected, $marks);

    expect($score)->toBe($expected)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($marks);
})->with('choice scores');
