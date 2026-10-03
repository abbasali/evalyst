<?php

namespace App\Http\Requests\Instructor;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Team;
use App\Support\QuestionRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class QuestionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('current_team');

        $rules = [
            ...QuestionRules::rules($team, $this->questionType()),
            'needs_verification' => ['sometimes', 'boolean'],
        ];

        // Student-facing fields of a locked question are ignored, so don't validate them (D-009).
        if ($this->isLocked()) {
            foreach (['type', 'body', 'code_language', 'options', 'options.*.body', 'options.*.is_correct'] as $field) {
                $rules[$field] = ['exclude'];
            }
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = $this->questionType();

            if (! $type || $this->isLocked() || $validator->errors()->hasAny(['options', 'options.*'])) {
                return;
            }

            if ($error = QuestionRules::correctOptionsError($type, (array) $this->input('options', []))) {
                $validator->errors()->add('options', $error);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'question',
            'options.*.body' => 'option text',
            'default_marks' => 'marks',
            'model_answer' => 'model answer',
            'code_language' => 'code language',
            'scoring_policy' => 'scoring policy',
        ];
    }

    private function isLocked(): bool
    {
        $question = $this->route('question');

        return $question instanceof Question && $question->isLocked();
    }

    private function questionType(): ?QuestionType
    {
        // A locked question keeps its type; its student-facing fields are frozen (D-009).
        if ($this->isLocked()) {
            /** @var Question $question */
            $question = $this->route('question');

            return $question->type;
        }

        return QuestionType::tryFrom((string) $this->input('type'));
    }
}
