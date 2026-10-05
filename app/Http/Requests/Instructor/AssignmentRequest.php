<?php

namespace App\Http\Requests\Instructor;

use App\Enums\AccessMode;
use App\Enums\LatePolicy;
use App\Enums\PenaltyType;
use App\Enums\ReleaseMode;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Assignment settings. Times arrive as `Y-m-d\TH:i` in the course timezone and are stored in UTC.
 */
class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        if ($assignment instanceof Assessment) {
            Gate::authorize('update', $assignment);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect(['opens_at', 'hard_cutoff_at', 'auto_publish_threshold', 'penalty_type', 'penalty_value', 'penalty_cap'])
            ->mapWithKeys(fn (string $field) => [$field => $this->filled($field) ? $this->input($field) : null])
            ->all());

        $this->merge([
            'extra_ignored_paths' => collect(preg_split('/\R/', (string) $this->input('extra_ignored_paths', '')) ?: [])
                ->map(fn (string $line) => trim($line))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $penalty = $this->input('late_policy') === LatePolicy::Penalty->value;

        return [
            'title' => ['required', 'string', 'max:150'],
            'instructions' => ['required', 'string', 'max:50000'],
            'opens_at' => ['nullable', 'date', 'before:closes_at'],
            'closes_at' => ['required', 'date'],
            'late_policy' => ['required', Rule::enum(LatePolicy::class)],
            'penalty_type' => [Rule::requiredIf($penalty), 'nullable', Rule::enum(PenaltyType::class)],
            'penalty_value' => [Rule::requiredIf($penalty), 'nullable', 'numeric', 'gt:0', 'max:1000'],
            'penalty_cap' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'hard_cutoff_at' => ['nullable', 'date', 'after:closes_at'],
            'allow_resubmission' => ['boolean'],
            'show_rules_to_students' => ['boolean'],
            'extra_ignored_paths' => ['array', 'max:50'],
            'extra_ignored_paths.*' => ['string', 'max:200'],
            'release_results' => ['boolean'],
            'release_mode' => ['required', Rule::enum(ReleaseMode::class)],
            'auto_publish_threshold' => ['nullable', 'numeric', 'between:0.5,1'],
            'access_mode' => ['required', Rule::enum(AccessMode::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opens_at.before' => __('The assignment must open before the deadline.'),
            'hard_cutoff_at.after' => __('The hard cutoff must be after the deadline.'),
            'penalty_type.required' => __('Choose how the penalty is calculated.'),
            'penalty_value.required' => __('Enter the marks deducted.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $this->validated();

            if ($data['penalty_cap'] !== null && $data['penalty_value'] !== null && (float) $data['penalty_cap'] < (float) $data['penalty_value']) {
                $validator->errors()->add('penalty_cap', __('The cap must be at least the penalty value.'));
            }

            $assignment = $this->route('assignment');

            if ($assignment instanceof Assessment && $data['access_mode'] !== $assignment->access_mode->value
                && (! $assignment->isDraft() || $assignment->participants()->exists())) {
                $validator->errors()->add('access_mode', __('The access mode can\'t change once the assignment is published or has students.'));
            }
        }];
    }

    /**
     * Validated data ready for the model.
     *
     * @return array<string, mixed>
     */
    public function assignmentAttributes(): array
    {
        $data = $this->validated();
        $existing = $this->route('assignment');
        $timezone = $this->courseTimezone();
        $policy = LatePolicy::from($data['late_policy']);
        $penalty = $policy === LatePolicy::Penalty;

        return [
            ...$data,
            'opens_at' => $data['opens_at'] ? Date::parse($data['opens_at'], $timezone)->utc() : null,
            'closes_at' => Date::parse($data['closes_at'], $timezone)->utc(),
            'late_policy' => $policy,
            'penalty_type' => $penalty ? PenaltyType::from($data['penalty_type']) : null,
            'penalty_value' => $penalty ? round((float) $data['penalty_value'], 2) : null,
            'penalty_cap' => $penalty && $data['penalty_cap'] !== null ? round((float) $data['penalty_cap'], 2) : null,
            'grace_minutes' => (int) $data['grace_minutes'],
            'hard_cutoff_at' => $policy !== LatePolicy::NotAllowed && $data['hard_cutoff_at'] ? Date::parse($data['hard_cutoff_at'], $timezone)->utc() : null,
            'allow_resubmission' => $this->boolean('allow_resubmission'),
            'show_rules_to_students' => $this->boolean('show_rules_to_students'),
            'extra_ignored_paths' => $data['extra_ignored_paths'] ?: null,
            // Left out: keep the current value (on for a new one), so hidden results never reappear by accident.
            'release_results' => $this->boolean('release_results', $existing instanceof Assessment ? $existing->release_results : true),
            'release_mode' => ReleaseMode::from($data['release_mode']),
            'access_mode' => AccessMode::from($data['access_mode']),
            'auto_publish_threshold' => $data['auto_publish_threshold'] !== null ? round((float) $data['auto_publish_threshold'], 2) : null,
            'duration_minutes' => null,
        ];
    }

    private function courseTimezone(): string
    {
        $team = $this->route('current_team');

        return $team instanceof Team ? $team->timezone : Team::DEFAULT_TIMEZONE;
    }
}
