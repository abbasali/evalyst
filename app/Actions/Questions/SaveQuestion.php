<?php

namespace App\Actions\Questions;

use App\Actions\Audit\RecordAudit;
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
     * @param  array<string, mixed>  $createAttributes  Extra attributes for new questions (e.g. AI source).
     */
    public function handle(Team $team, User $user, array $data, ?Question $question = null, array $createAttributes = []): Question
    {
        return DB::transaction(function () use ($team, $user, $data, $question, $createAttributes) {
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
                $before = $question->only(['model_answer', 'rubric', 'scoring_policy', 'default_marks']);
                $question->update($attributes);
                $this->auditGradingChange($user, $question, $locked, $before);
            } else {
                $question = $team->questions()->create([
                    ...$attributes,
                    'source' => QuestionSource::Manual,
                    'created_by' => $user->id,
                    ...$createAttributes,
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
     * Changing how a locked (already answered) question is graded is audit-logged (D-009).
     *
     * @param  array<string, mixed>  $before
     */
    private function auditGradingChange(User $user, Question $question, bool $locked, array $before): void
    {
        $changed = array_keys($question->getChanges());
        $fields = array_values(array_intersect(['model_answer', 'rubric', 'scoring_policy'], $changed));

        if (! $locked || $fields === []) {
            return;
        }

        app(RecordAudit::class)->handle($user, $question, 'question.rubric_update', [
            'before' => array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, array_intersect_key($before, array_flip($fields))),
            'after' => array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $question->only($fields)),
        ]);
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
