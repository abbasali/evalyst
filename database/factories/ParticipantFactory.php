<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Student;
use App\Support\AccessCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    /**
     * The student is created in the assessment's course.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'student_id' => fn (array $attributes) => Student::factory()->state([
                'team_id' => Assessment::query()->whereKey($attributes['assessment_id'])->value('team_id'),
            ]),
        ];
    }

    public function withCode(): static
    {
        return $this->state(fn () => ['access_code' => AccessCode::generate(AccessCode::ROSTER_LENGTH)]);
    }
}
