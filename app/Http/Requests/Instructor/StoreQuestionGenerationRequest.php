<?php

namespace App\Http\Requests\Instructor;

use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreQuestionGenerationRequest extends FormRequest
{
    public const TYPES = ['single_choice', 'multiple_choice', 'open_text', 'open_code'];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('current_team');

        return [
            'prompt' => ['required', 'string', 'min:10', 'max:1000'],
            'type_counts' => ['required', 'array:'.implode(',', self::TYPES)],
            ...collect(self::TYPES)->mapWithKeys(fn (string $type) => ["type_counts.{$type}" => ['required', 'integer', 'min:0', 'max:15']])->all(),
            'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard', 'mixed'])],
            'include_code_output' => ['required', 'boolean'],
            'tag_ids' => ['array', 'max:10'],
            'tag_ids.*' => ['integer', Rule::exists('tags', 'id')->where('team_id', $team->id)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Team $team */
            $team = $this->route('current_team');
            $total = array_sum(array_map('intval', (array) $this->input('type_counts', [])));
            $max = (int) config('evalyst.ai.max_generation_questions');

            if ($total < 1 || $total > $max) {
                $validator->errors()->add('type_counts', __('Ask for between 1 and :max questions in total.', ['max' => $max]));
            }

            $running = $team->questionGenerations()->whereIn('status', ['pending', 'running'])->count();

            if ($running >= (int) config('evalyst.ai.max_concurrent_generations')) {
                $validator->errors()->add('prompt', __('Please wait for running generations to finish.'));
            }
        }];
    }
}
