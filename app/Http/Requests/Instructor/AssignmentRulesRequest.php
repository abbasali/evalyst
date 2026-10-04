<?php

namespace App\Http\Requests\Instructor;

use App\Enums\AutomatedCheck;
use App\Enums\RuleKind;
use App\Models\Assessment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole ordered rule list of an assignment, saved at once.
 */
class AssignmentRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Assessment $assignment */
        $assignment = $this->route('assignment');
        Gate::authorize('update', $assignment);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array', 'max:30'],
            'rules.*.id' => ['nullable', 'integer'],
            'rules.*.kind' => ['required', Rule::enum(RuleKind::class)],
            'rules.*.title' => ['required', 'string', 'max:150'],
            'rules.*.description' => ['nullable', 'string', 'max:2000', 'required_if:rules.*.kind,ai'],
            'rules.*.check' => ['nullable', 'required_if:rules.*.kind,automated', Rule::enum(AutomatedCheck::class)],
            'rules.*.config' => ['nullable', 'array'],
            'rules.*.marks' => ['required', 'numeric', 'gt:0', 'max:1000', 'multiple_of:0.5'],
            'regrade' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.*.description.required_if' => __('Describe what the AI should judge.'),
            'rules.*.check.required_if' => __('Choose a check.'),
            'rules.*.marks.multiple_of' => __('Use steps of 0.5.'),
        ];
    }

    /**
     * Per-check config validation (a missing key, an empty glob or a regex that doesn't compile).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach ((array) $this->input('rules', []) as $index => $rule) {
                if (! is_array($rule) || ($rule['kind'] ?? null) !== RuleKind::Automated->value) {
                    continue;
                }

                $check = AutomatedCheck::tryFrom((string) ($rule['check'] ?? ''));
                $config = is_array($rule['config'] ?? null) ? $rule['config'] : [];

                foreach ($check ? self::configErrors($check, $config) : [] as $key => $message) {
                    $validator->errors()->add("rules.{$index}.config.{$key}", $message);
                }
            }
        }];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function configErrors(AutomatedCheck $check, array $config): array
    {
        $errors = [];
        $positiveInt = fn (string $key) => isset($config[$key]) && filter_var($config[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) !== false;
        $glob = fn () => is_string($config['glob'] ?? null) && trim($config['glob']) !== '';
        $regex = fn () => is_string($config['pattern'] ?? null) && $config['pattern'] !== '' && @preg_match(self::delimit($config['pattern']), '') !== false;

        match ($check) {
            AutomatedCheck::MinCommits, AutomatedCheck::MinCommitDays => $positiveInt('min') ?: $errors['min'] = __('Enter a whole number of at least 1.'),
            AutomatedCheck::CommitMessagePattern => [
                $regex() ?: $errors['pattern'] = __('This isn\'t a valid regular expression.'),
                is_numeric($config['min_ratio'] ?? null) && $config['min_ratio'] > 0 && $config['min_ratio'] <= 1
                    ?: $errors['min_ratio'] = __('Enter a share between 1% and 100%.'),
            ],
            AutomatedCheck::PathExists => [
                $glob() ?: $errors['glob'] = __('Enter a path or glob.'),
                ! isset($config['min_matches']) || $positiveInt('min_matches') ?: $errors['min_matches'] = __('Enter a whole number of at least 1.'),
            ],
            AutomatedCheck::PathAbsent => $glob() ?: $errors['glob'] = __('Enter a path or glob.'),
            AutomatedCheck::FileContains => [
                $glob() ?: $errors['glob'] = __('Enter a path or glob.'),
                $regex() ?: $errors['pattern'] = __('This isn\'t a valid regular expression.'),
            ],
        };

        return $errors;
    }

    /**
     * Instructors type a bare pattern; wrap it for preg_*.
     */
    public static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }
}
