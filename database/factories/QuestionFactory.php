<?php

namespace Database\Factories;

use App\Enums\ChoiceScoringPolicy;
use App\Enums\CodeLanguage;
use App\Enums\QuestionSource;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    /**
     * Defaults to a single-choice question with four options.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => QuestionType::SingleChoice,
            'body' => fake()->sentence().'?',
            'default_marks' => 1,
            'source' => QuestionSource::Manual,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Question $question) {
            if (! $question->type->isChoice() || $question->options()->exists()) {
                return;
            }

            $correct = $question->type === QuestionType::SingleChoice ? [0] : [0, 1];

            foreach (range(0, 3) as $position) {
                $question->options()->create([
                    'body' => fake()->unique()->words(3, true),
                    'is_correct' => in_array($position, $correct, true),
                    'position' => $position,
                ]);
            }
        });
    }

    public function singleChoice(): static
    {
        return $this->state(['type' => QuestionType::SingleChoice]);
    }

    public function multipleChoice(): static
    {
        return $this->state([
            'type' => QuestionType::MultipleChoice,
            'scoring_policy' => ChoiceScoringPolicy::AllOrNothing,
        ]);
    }

    public function openText(): static
    {
        return $this->state([
            'type' => QuestionType::OpenText,
            'model_answer' => fake()->paragraph(),
            'rubric' => "- Mentions the key idea (1 mark)\n- Gives an example (1 mark)",
            'default_marks' => 2,
        ]);
    }

    public function openCode(): static
    {
        return $this->state([
            'type' => QuestionType::OpenCode,
            'code_language' => CodeLanguage::Php,
            'model_answer' => "```php\n<?php echo 'Hello';\n```",
            'default_marks' => 3,
        ]);
    }

    public function aiGenerated(): static
    {
        return $this->state(['source' => QuestionSource::Ai]);
    }

    public function locked(): static
    {
        return $this->state(['locked_at' => now()]);
    }
}
