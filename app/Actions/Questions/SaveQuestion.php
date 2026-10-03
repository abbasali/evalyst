<?php

namespace App\Actions\Questions;

use App\Enums\QuestionSource;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveQuestion
{
    /**
     * Fields that only affect grading; editable even on locked questions (D-009).
     */
    private const GRADING_FIELDS = ['default_marks', 'scoring_policy', 'model_answer', 'rubric', 'explanation', 'difficulty', 'needs_verification'];

    /**
     * Create a question, or update one (options are synced by position so IDs survive).
     *
     * @param  array<string, mixed>  $data  Validated QuestionRequest data.
     */
    public function handle(Team $team, User $user, array $data, ?Question $question = null): Question
    {
        return DB::transaction(function () use ($team, $user, $data, $question) {
            $locked = $question?->isLocked() ?? false;
            $type = $locked ? $question->type : QuestionType::from($data['type']);

            $attributes = Arr::only($data, $locked ? self::GRADING_FIELDS : [
                'type', 'body', 'code_language', ...self::GRADING_FIELDS,
            ]);

            // Drop fields that don't apply to the type.
            if ($type !== QuestionType::MultipleChoice) {
                $attributes['scoring_policy'] = null;
            }

            if (! $locked && $type !== QuestionType::OpenCode) {
                $attributes['code_language'] = null;
            }

            if ($type->isChoice()) {
                $attributes['model_answer'] = null;
            }

            if ($question) {
                $question->update($attributes);
            } else {
                $question = $team->questions()->create([
                    ...$attributes,
                    'source' => QuestionSource::Manual,
                    'created_by' => $user->id,
                ]);
            }

            if (! $locked) {
                $this->syncOptions($question, $type->isChoice() ? $data['options'] ?? [] : []);
            }

            $question->tags()->sync($data['tag_ids'] ?? []);

            return $question;
        });
    }

    /**
     * @param  array<int, array{body: string, is_correct: bool|string|int}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        $existing = $question->options()->get()->values();

        foreach (array_values($options) as $position => $option) {
            $values = [
                'body' => $option['body'],
                'is_correct' => filter_var($option['is_correct'], FILTER_VALIDATE_BOOLEAN),
                'position' => $position,
            ];

            if ($current = $existing->get($position)) {
                $current->update($values);
            } else {
                $question->options()->create($values);
            }
        }

        $question->options()->where('position', '>=', count($options))->delete();
    }
}
