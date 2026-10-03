<?php

namespace App\Actions\Questions;

use App\Enums\QuestionSource;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DuplicateQuestion
{
    /**
     * Copy a question (options and tags included) as a new, unlocked manual question.
     */
    public function handle(Question $question, User $user): Question
    {
        return DB::transaction(function () use ($question, $user) {
            $copy = $question->replicate(['locked_at', 'deleted_at']);
            $copy->source = QuestionSource::Manual;
            $copy->created_by = $user->id;
            $copy->save();

            foreach ($question->options as $option) {
                $copy->options()->create($option->only(['body', 'is_correct', 'position']));
            }

            $copy->tags()->sync($question->tags->modelKeys());

            return $copy;
        });
    }
}
