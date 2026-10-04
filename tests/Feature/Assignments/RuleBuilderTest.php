<?php

use App\Enums\AutomatedCheck;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use App\Models\Submission;

test('rules are saved in order with marks', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->for($team)->create();

    $this->put(route('assignments.rules.update', [$team, $assignment]), ['rules' => [
        ['kind' => 'ai', 'title' => 'Uses Form Requests', 'description' => 'Validation in Form Requests.', 'marks' => 5],
        ['kind' => 'automated', 'title' => 'Commits', 'check' => 'min_commits', 'config' => ['min' => 10], 'marks' => 2],
    ]])->assertSessionHasNoErrors();

    expect($assignment->rules()->pluck('title')->all())->toBe(['Uses Form Requests', 'Commits'])
        ->and($assignment->maxScore())->toBe(7.0);
});

test('invalid check config is rejected', function (array $rule, string $error) {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->for($team)->create();

    $this->put(route('assignments.rules.update', [$team, $assignment]), ['rules' => [['kind' => 'automated', 'title' => 'x', 'marks' => 1, ...$rule]]])
        ->assertSessionHasErrors($error);
})->with([
    'bad regex' => [['check' => 'file_contains', 'config' => ['glob' => 'app/*.php', 'pattern' => '(unclosed']], 'rules.0.config.pattern'],
    'missing min' => [['check' => 'min_commits', 'config' => []], 'rules.0.config.min'],
    'empty glob' => [['check' => 'path_absent', 'config' => ['glob' => ' ']], 'rules.0.config.glob'],
    'ratio over 100%' => [['check' => 'commit_message_pattern', 'config' => ['pattern' => '^feat', 'min_ratio' => 2]], 'rules.0.config.min_ratio'],
    'ai without description' => [['kind' => 'ai', 'description' => ''], 'rules.0.description'],
]);

test('a rule with grades cannot be removed', function () {
    [, $team] = actingAsInstructor();
    $assignment = Assessment::factory()->assignment()->for($team)->create();
    $rule = AssignmentRule::factory()->automated(AutomatedCheck::PathAbsent, ['glob' => '.env'])->for($assignment, 'assessment')->create();
    $submission = Submission::factory()->create();
    $submission->ruleResults()->create(['assignment_rule_id' => $rule->id, 'score' => 1, 'max_score' => 1]);

    $this->put(route('assignments.rules.update', [$team, $assignment]), ['rules' => []])
        ->assertSessionHasErrors('rules');

    expect($rule->fresh())->not->toBeNull();
});
