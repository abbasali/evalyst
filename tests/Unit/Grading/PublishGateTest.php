<?php

use App\Grading\AiGradeResult;
use App\Grading\PublishGate;

function aiGrade(float $score = 2, float $confidence = 0.9, array $flags = [], string $feedback = 'Good.'): AiGradeResult
{
    return new AiGradeResult($score, $feedback, [], $confidence, $flags);
}

dataset('gate cases', [
    'confident, no flags' => [aiGrade(), true, []],
    'confidence exactly at threshold' => [aiGrade(confidence: 0.8), true, []],
    'low confidence' => [aiGrade(confidence: 0.79), false, ['low_confidence']],
    'one flag' => [aiGrade(flags: ['off_topic']), false, ['flag:off_topic']],
    'blank with zero score' => [aiGrade(score: 0, flags: ['blank_or_minimal']), true, []],
    'blank with a score' => [aiGrade(score: 1, flags: ['blank_or_minimal']), false, ['flag:blank_or_minimal']],
    'score above max' => [aiGrade(score: 5), false, ['invalid_output']],
    'negative score' => [aiGrade(score: -1), false, ['invalid_output']],
    'empty feedback' => [aiGrade(feedback: ' '), false, ['invalid_output']],
    'several reasons' => [aiGrade(confidence: 0.5, flags: ['prompt_injection']), false, ['low_confidence', 'flag:prompt_injection']],
]);

test('the gate publishes only confident, unflagged, valid grades', function (AiGradeResult $result, bool $publish, array $reasons) {
    $decision = (new PublishGate)->decide($result, 4, 0.8);

    expect($decision->publish)->toBe($publish)->and($decision->reasons)->toBe($reasons);
})->with('gate cases');

dataset('project gate cases', [
    'all confident' => [[0.9, 0.85], [], true],
    'one rule below' => [[0.9, 0.7], [], false],
    'flagged' => [[0.9], ['repo_mostly_empty'], false],
    'no ai rules' => [[], [], true],
]);

test('projects need every rule to be confident', function (array $confidences, array $flags, bool $publish) {
    expect((new PublishGate)->decideProject($confidences, $flags, 0.8)->publish)->toBe($publish);
})->with('project gate cases');
