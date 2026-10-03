<?php

use App\Ai\Validation\GeneratedQuestionValidator;
use App\Ai\Validation\VerificationComparator;

test('invalid generated questions are dropped with a warning', function (array $question, string $warning) {
    $result = (new GeneratedQuestionValidator)->validate([$question], ['single_choice' => 1, 'multiple_choice' => 1, 'open_code' => 1]);

    expect($result['drafts'])->toBe([])
        ->and($result['warnings'][0])->toContain($warning);
})->with([
    'two correct in single choice' => [['type' => 'single_choice', 'body' => 'Q', 'options' => [['body' => 'a', 'is_correct' => true], ['body' => 'b', 'is_correct' => true]]], 'exactly one correct'],
    'one correct in multiple choice' => [['type' => 'multiple_choice', 'body' => 'Q', 'options' => [['body' => 'a', 'is_correct' => true], ['body' => 'b', 'is_correct' => false], ['body' => 'c', 'is_correct' => false]]], 'two or more correct'],
    'duplicate options' => [['type' => 'single_choice', 'body' => 'Q', 'options' => [['body' => 'a', 'is_correct' => true], ['body' => 'A', 'is_correct' => false]]], 'duplicate options'],
    'code question without language' => [['type' => 'open_code', 'body' => 'Q', 'model_answer' => 'x', 'code_language' => 'cobol'], 'code language'],
    'unknown type' => [['type' => 'essay', 'body' => 'Q'], 'unknown question type'],
]);

test('the validator reports missing counts and clamps marks', function () {
    $result = (new GeneratedQuestionValidator)->validate([
        ['type' => 'open_text', 'body' => 'Q', 'model_answer' => 'A', 'suggested_marks' => 250],
    ], ['open_text' => 1, 'single_choice' => 2]);

    expect($result['drafts'][0]['suggested_marks'])->toBe(100.0)
        ->and($result['missing'])->toBe(['single_choice' => 2]);
});

test('the comparator disputes disagreement and low confidence', function (array $result, string $status) {
    $draft = ['type' => 'multiple_choice', 'options' => [['is_correct' => true], ['is_correct' => false], ['is_correct' => true]]];

    expect((new VerificationComparator)->compare([$draft], [$result])[0]['verification']['status'])->toBe($status);
})->with([
    'same answer' => [['index' => 0, 'selected' => [2, 0], 'confidence' => 0.9, 'reasoning' => ''], 'agreed'],
    'different answer' => [['index' => 0, 'selected' => [0], 'confidence' => 0.9, 'reasoning' => ''], 'disputed'],
    'unsure' => [['index' => 0, 'selected' => [0, 2], 'confidence' => 0.5, 'reasoning' => ''], 'disputed'],
    'missing' => [['index' => 3, 'selected' => [0, 2], 'confidence' => 0.9, 'reasoning' => ''], 'disputed'],
]);
