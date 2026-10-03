<?php

namespace Database\Factories;

use App\Models\Student;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->name(),
            'roll_number' => fake()->unique()->bothify('CS-####'),
            'email' => fake()->optional()->safeEmail(),
        ];
    }
}
