<?php

namespace Database\Factories;

use App\Enums\AutomatedCheck;
use App\Enums\RuleKind;
use App\Models\Assessment;
use App\Models\AssignmentRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentRule>
 */
class AssignmentRuleFactory extends Factory
{
    /**
     * Defaults to an AI rule worth 5 marks.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory()->assignment(),
            'kind' => RuleKind::Ai,
            'title' => 'Validation uses Form Requests',
            'description' => 'Every store/update action validates input with a Form Request class.',
            'marks' => 5,
            'position' => 1,
        ];
    }

    public function ai(): static
    {
        return $this->state(['kind' => RuleKind::Ai, 'check' => null, 'config' => null]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function automated(AutomatedCheck $check = AutomatedCheck::MinCommits, array $config = ['min' => 5]): static
    {
        return $this->state([
            'kind' => RuleKind::Automated,
            'title' => $check->label(),
            'description' => null,
            'check' => $check,
            'config' => $config,
        ]);
    }
}
