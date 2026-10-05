<?php

namespace App\Http\Requests\Instructor;

use App\Enums\AccessMode;
use App\Enums\ReleaseMode;
use App\Models\Assessment;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Quiz settings. Times arrive as `Y-m-d\TH:i` in the course timezone and are stored in UTC.
 */
class QuizRequest extends FormRequest
{
    /**
     * Settings that can't change once a student has started (closes_at may only move later).
     */
    public const LOCKED_ONCE_STARTED = [
        'opens_at', 'duration_minutes', 'shuffle_questions', 'shuffle_options', 'track_focus',
        'one_way_navigation', 'require_fullscreen', 'access_mode',
    ];

    /**
     * Archived quizzes are read-only (throws with the policy's message).
     */
    public function authorize(): bool
    {
        $quiz = $this->route('quiz');

        if ($quiz instanceof Assessment) {
            Gate::authorize('update', $quiz);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'opens_at' => $this->filled('opens_at') ? $this->input('opens_at') : null,
            'auto_publish_threshold' => $this->filled('auto_publish_threshold') ? $this->input('auto_publish_threshold') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:20000'],
            'opens_at' => ['nullable', 'date', 'before:closes_at'],
            'closes_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'shuffle_questions' => ['boolean'],
            'shuffle_options' => ['boolean'],
            'show_answers_after_release' => ['boolean'],
            'track_focus' => ['boolean'],
            'one_way_navigation' => ['boolean'],
            'require_fullscreen' => ['boolean'],
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
            'opens_at.before' => __('The quiz must open before it closes.'),
            'auto_publish_threshold.between' => __('The threshold must be between 0.50 and 1.00.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        $quiz = $this->route('quiz');

        if (! $quiz instanceof Assessment) {
            return [];
        }

        return [function (Validator $validator) use ($quiz) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $attributes = $this->quizAttributes();

            if ($attributes['access_mode'] !== $quiz->access_mode && $quiz->participants()->exists()) {
                $validator->errors()->add('access_mode', __('The access mode can\'t change once participants have been added.'));
            } elseif ($attributes['access_mode'] !== $quiz->access_mode && ! $quiz->isDraft()) {
                $validator->errors()->add('access_mode', __('Move the quiz back to draft to change how students get in.'));
            }

            if (! $quiz->hasAttempts()) {
                return;
            }

            foreach (self::LOCKED_ONCE_STARTED as $field) {
                if ($this->differs($quiz->getAttribute($field), $attributes[$field])) {
                    $validator->errors()->add($field, __('Locked because students have started.'));
                }
            }

            if ($attributes['closes_at']->lt($quiz->closes_at->startOfMinute())) {
                $validator->errors()->add('closes_at', __('Students have started, so the closing time can only be extended.'));
            }
        }];
    }

    /**
     * Validated data ready for the model: times converted to UTC, enums cast.
     *
     * @return array<string, mixed>
     */
    public function quizAttributes(): array
    {
        $data = $this->validated();
        $existing = $this->route('quiz');
        $timezone = $this->courseTimezone();

        return [
            ...$data,
            'opens_at' => $data['opens_at'] ? Date::parse($data['opens_at'], $timezone)->utc() : null,
            'closes_at' => Date::parse($data['closes_at'], $timezone)->utc(),
            'duration_minutes' => (int) $data['duration_minutes'],
            'shuffle_questions' => $this->boolean('shuffle_questions'),
            'shuffle_options' => $this->boolean('shuffle_options'),
            'show_answers_after_release' => $this->boolean('show_answers_after_release'),
            'track_focus' => $this->boolean('track_focus'),
            'one_way_navigation' => $this->boolean('one_way_navigation'),
            'require_fullscreen' => $this->boolean('require_fullscreen'),
            // Left out: keep the current value (on for a new one), so hidden results never reappear by accident.
            'release_results' => $this->boolean('release_results', $existing instanceof Assessment ? $existing->release_results : true),
            'release_mode' => ReleaseMode::from($data['release_mode']),
            'access_mode' => AccessMode::from($data['access_mode']),
            'auto_publish_threshold' => $data['auto_publish_threshold'] !== null ? round((float) $data['auto_publish_threshold'], 2) : null,
        ];
    }

    private function courseTimezone(): string
    {
        $team = $this->route('current_team');

        return $team instanceof Team ? $team->timezone : Team::DEFAULT_TIMEZONE;
    }

    private function differs(mixed $current, mixed $new): bool
    {
        if ($current instanceof CarbonInterface || $new instanceof CarbonInterface) {
            // The form round-trips at minute precision.
            return ! ($current instanceof CarbonInterface && $new instanceof CarbonInterface && $current->startOfMinute()->equalTo($new->startOfMinute()));
        }

        return $current !== $new;
    }
}
