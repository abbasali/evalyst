<?php

namespace Database\Factories;

use App\Enums\AttemptStatus;
use App\Models\Attempt;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attempt>
 */
class AttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'status' => AttemptStatus::InProgress,
            'started_at' => now(),
            'deadline_at' => now()->addMinutes(30),
            'question_order' => [],
            'resume_token' => hash('sha256', Str::random(64)),
            'max_score' => 0,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => AttemptStatus::InProgress]);
    }

    public function submitted(): static
    {
        return $this->state(['status' => AttemptStatus::Submitted, 'submitted_at' => now()]);
    }
}
