<?php

use App\Ai\Agents\QuestionGenerator;
use App\Ai\Agents\QuestionVerifier;
use App\Ai\AiCost;
use App\Enums\GenerationStatus;
use App\Jobs\GenerateQuestions;
use App\Models\AiRun;
use App\Models\QuestionGeneration;
use App\Models\Team;

function generation(array $counts = ['single_choice' => 1, 'open_text' => 1]): QuestionGeneration
{
    return QuestionGeneration::create([
        'team_id' => Team::factory()->create()->id,
        'prompt' => 'PHP arrays',
        'type_counts' => $counts,
        'difficulty' => 'mixed',
        'include_code_output' => true,
        'status' => GenerationStatus::Pending,
    ]);
}

function singleChoiceDraft(string $body = 'What does sort() do?'): array
{
    return [
        'type' => 'single_choice', 'body' => $body, 'code_language' => null,
        'options' => [['body' => 'Sorts in place', 'is_correct' => true], ['body' => 'Returns a copy', 'is_correct' => false]],
        'model_answer' => null, 'rubric' => null, 'explanation' => 'It sorts by reference.',
        'difficulty' => 'easy', 'suggested_marks' => 1, 'is_code_output' => false,
    ];
}

function openTextDraft(): array
{
    return [
        'type' => 'open_text', 'body' => 'Explain array_map.', 'code_language' => null, 'options' => [],
        'model_answer' => 'Applies a callback to each element.', 'rubric' => '- Callback (1 mark)',
        'explanation' => 'array_map returns a new array.', 'difficulty' => 'easy', 'suggested_marks' => 2, 'is_code_output' => false,
    ];
}

test('a generation stores verified drafts and logs two AI runs', function () {
    config(['evalyst.ai.pricing.gpt-5.4-mini' => ['input' => 1, 'output' => 1]]);
    QuestionGenerator::fake([['questions' => [singleChoiceDraft(), openTextDraft()]]]);
    QuestionVerifier::fake([['results' => [['index' => 0, 'selected' => [0], 'confidence' => 0.95, 'reasoning' => 'In place.']]]]);

    $generation = generation();
    GenerateQuestions::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Completed)
        ->and($generation->drafts)->toHaveCount(2)
        ->and($generation->drafts[0]['verification']['status'])->toBe('agreed')
        ->and($generation->drafts[1]['verification']['status'])->toBe('not_applicable')
        ->and($generation->drafts[0]['uid'])->not->toBeEmpty()
        ->and(AiRun::where('subject_id', $generation->id)->count())->toBe(2);

    QuestionVerifier::assertPrompted(fn ($prompt) => ! str_contains($prompt->prompt, 'is_correct') && ! str_contains($prompt->prompt, 'true'));
});

test('missing questions are requested once more and disputes are flagged', function () {
    QuestionGenerator::fake([
        ['questions' => [openTextDraft()]],
        ['questions' => [singleChoiceDraft()]],
    ]);
    QuestionVerifier::fake([['results' => [['index' => 1, 'selected' => [1], 'confidence' => 0.9, 'reasoning' => 'Returns a copy.']]]]);

    $generation = generation();
    GenerateQuestions::dispatchSync($generation);

    $generation->refresh();
    expect($generation->drafts)->toHaveCount(2)
        ->and($generation->drafts[1]['verification']['status'])->toBe('disputed')
        ->and($generation->drafts[1]['verification']['verifier_selected'])->toBe([1])
        ->and(AiRun::where('subject_id', $generation->id)->count())->toBe(3);
});

test('a failing generation is marked failed and the run is logged', function () {
    QuestionGenerator::fake(fn () => throw new RuntimeException('OpenAI is down'));

    $generation = generation(['open_text' => 1]);
    $job = new GenerateQuestions($generation);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('OpenAI is down'));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->error)->toContain('OpenAI is down')
        ->and(AiRun::where('succeeded', false)->count())->toBe(1);
});

test('AI cost uses the configured pricing per million tokens', function () {
    config(['evalyst.ai.pricing' => ['m' => ['input' => 0.75, 'output' => 4.5]]]);

    expect(AiCost::for('m', 2_000, 500))->toBe(0.00375)
        ->and(AiCost::for('unknown', 2_000, 500))->toBe(0.0);
});
