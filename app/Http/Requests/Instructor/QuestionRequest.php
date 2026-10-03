<?php

namespace App\Http\Requests\Instructor;

use App\Enums\ChoiceScoringPolicy;
use App\Enums\CodeLanguage;
use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $type = $this->questionType();

        $optionRules = $type?->isChoice() ? [
            'options' => ['required', 'array', 'min:'.($type === QuestionType::SingleChoice ? 2 : 3), 'max:8'],
            'options.*.body' => ['required', 'string', 'max:2000', 'distinct:ignore_case'],
            'options.*.is_correct' => ['required', 'boolean'],
        ] : [];

        return [
            'type' => ['required', Rule::enum(QuestionType::class)],
            'body' => ['required', 'string', 'max:20000'],
            'code_language' => [Rule::requiredIf($type === QuestionType::OpenCode), 'nullable', Rule::enum(CodeLanguage::class)],
            'default_marks' => ['required', 'numeric', 'min:0.5', 'max:100', 'multiple_of:0.5'],
            'scoring_policy' => [Rule::requiredIf($type === QuestionType::MultipleChoice), 'nullable', Rule::enum(ChoiceScoringPolicy::class)],
            'model_answer' => [Rule::requiredIf((bool) $type?->isOpen()), 'nullable', 'string', 'max:20000'],
            'rubric' => ['nullable', 'string', 'max:10000'],
            'explanation' => ['nullable', 'string', 'max:10000'],
            'difficulty' => ['nullable', Rule::enum(Difficulty::class)],
            'needs_verification' => ['sometimes', 'boolean'],
            'tag_ids' => ['array', 'max:10'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('team_id', $team->id)],
            ...$optionRules,
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = $this->questionType();

            if (! $type?->isChoice() || $validator->errors()->has('options')) {
                return;
            }

            /** @var array<int, array{is_correct?: mixed}> $options */
            $options = (array) $this->input('options', []);
            $correct = count(array_filter($options, fn (array $option) => filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)));
            $total = count($options);

            if ($type === QuestionType::SingleChoice && $correct !== 1) {
                $validator->errors()->add('options', __('Mark exactly one option as correct.'));
            }

            if ($type === QuestionType::MultipleChoice && ($correct < 2 || $correct === $total)) {
                $validator->errors()->add('options', __('Mark at least two correct options and leave at least one incorrect.'));
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

    private function questionType(): ?QuestionType
    {
        $question = $this->route('question');

        // A locked question keeps its type; its student-facing fields are frozen (D-009).
        if ($question instanceof Question && $question->isLocked()) {
            return $question->type;
        }

        return QuestionType::tryFrom((string) $this->input('type'));
    }
}
