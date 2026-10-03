<?php

namespace App\Http\Requests\Instructor;

use App\Models\Student;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'roll_number' => Student::normalizeRollNumber((string) $this->input('roll_number')),
            'email' => $this->filled('email') ? trim((string) $this->input('email')) : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('current_team');
        $student = $this->route('student');

        return [
            'name' => ['required', 'string', 'max:255'],
            'roll_number' => [
                'required', 'string', 'max:50',
                Rule::unique('students')
                    ->where('team_id', $team->id)
                    ->ignore($student instanceof Student ? $student->id : null),
            ],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roll_number.unique' => __('Another student in this course already has this roll number.'),
        ];
    }
}
