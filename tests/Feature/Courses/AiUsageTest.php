<?php

use App\Enums\AiRunPurpose;
use App\Models\AiRun;
use App\Models\Team;

function aiRun(Team $team, AiRunPurpose $purpose, float $cost, bool $succeeded = true): AiRun
{
    return AiRun::create([
        'team_id' => $team->id, 'purpose' => $purpose, 'subject_type' => 'answer', 'subject_id' => 1,
        'provider' => 'openai', 'model' => 'gpt-5.4-mini', 'input_tokens' => 1000, 'output_tokens' => 200,
        'cost_usd' => $cost, 'duration_ms' => 900, 'succeeded' => $succeeded,
    ]);
}

test('usage sums this course\'s runs only', function () {
    [, $team] = actingAsInstructor();
    aiRun($team, AiRunPurpose::OpenAnswerGrading, 0.5);
    aiRun($team, AiRunPurpose::ProjectGrading, 1.25, succeeded: false);
    aiRun(Team::factory()->create(), AiRunPurpose::ProjectGrading, 9);
    $this->travel(-40)->days();
    aiRun($team, AiRunPurpose::QuestionGeneration, 2);
    $this->travelBack();

    $this->get(route('ai-usage', $team))
        ->assertInertia(fn ($page) => $page
            ->component('courses/AiUsage')
            ->where('month.cost', 1.75)
            ->where('month.failures', 1)
            ->where('allTime.cost', 3.75)
            ->where('allTime.runs', 3)
            ->has('expensive', 3));
});
