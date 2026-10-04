<?php

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Participant;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'repo_url' => 'https://github.com/student/blog',
            'repo_owner' => 'student',
            'repo_name' => 'blog',
            'commit_sha' => fake()->sha1(),
            'default_branch' => 'main',
            'submitted_at' => now(),
            'is_current' => true,
            'minutes_late' => 0,
            'status' => SubmissionStatus::Submitted,
            'penalty' => 0,
            'max_score' => 10,
        ];
    }

    public function late(int $minutes): static
    {
        return $this->state(['minutes_late' => $minutes]);
    }

    public function graded(float $raw, float $penalty = 0): static
    {
        return $this->state([
            'status' => SubmissionStatus::Final,
            'raw_score' => $raw,
            'penalty' => $penalty,
            'score' => max(0, $raw - $penalty),
            'published_at' => now(),
        ]);
    }
}
