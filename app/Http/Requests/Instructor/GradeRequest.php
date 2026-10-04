<?php

namespace App\Http\Requests\Instructor;

use App\Models\Answer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Answer $answer */
        $answer = $this->route('answer');

        return [
            'score' => ['required', 'numeric', 'min:0', 'max:'.(float) $answer->max_score, 'multiple_of:0.5'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['score.multiple_of' => __('Use steps of 0.5.')];
    }
}
