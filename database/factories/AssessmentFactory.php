<?php

namespace Database\Factories;

use App\Enums\AccessMode;
use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Enums\ReleaseMode;
use App\Models\Assessment;
use App\Models\Team;
use App\Support\AccessCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    /**
     * Defaults to a draft roster-mode quiz closing in a week.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => AssessmentType::Quiz,
            'title' => fake()->sentence(3),
            'status' => AssessmentStatus::Draft,
            'access_mode' => AccessMode::Roster,
            'opens_at' => null,
            'closes_at' => now()->addWeek(),
            'release_mode' => ReleaseMode::Manual,
            'duration_minutes' => 30,
        ];
    }

    public function quiz(): static
    {
        return $this->state(['type' => AssessmentType::Quiz, 'duration_minutes' => 30]);
    }

    public function assignment(): static
    {
        return $this->state(['type' => AssessmentType::Assignment, 'duration_minutes' => null]);
    }

    public function draft(): static
    {
        return $this->state(['status' => AssessmentStatus::Draft]);
    }

    public function published(): static
    {
        return $this->state(['status' => AssessmentStatus::Published]);
    }

    public function archived(): static
    {
        return $this->state(['status' => AssessmentStatus::Archived]);
    }

    public function open(): static
    {
        return $this->published()->state(['opens_at' => now()->subHour(), 'closes_at' => now()->addDay()]);
    }

    public function upcoming(): static
    {
        return $this->published()->state(['opens_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
    }

    public function closed(): static
    {
        return $this->published()->state(['opens_at' => now()->subDays(2), 'closes_at' => now()->subDay()]);
    }

    public function rosterMode(): static
    {
        return $this->state(['access_mode' => AccessMode::Roster, 'shared_code' => null]);
    }

    public function sharedCode(): static
    {
        return $this->state(fn () => [
            'access_mode' => AccessMode::SharedCode,
            'shared_code' => AccessCode::generate(AccessCode::SHARED_LENGTH),
        ]);
    }
}
