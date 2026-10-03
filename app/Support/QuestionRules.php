<?php

namespace App\Support;

use App\Enums\ChoiceScoringPolicy;
use App\Enums\CodeLanguage;
use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Models\Team;
use Illuminate\Validation\Rule;

/**
 * Validation rules for a question, shared by the question form and accepting AI drafts.
 */
class QuestionRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(Team $team, ?QuestionType $type, string $prefix = ''): array
    {
        $optionRules = $type?->isChoice() ? [
            "{$prefix}options" => ['required', 'array', 'min:'.($type === QuestionType::SingleChoice ? 2 : 3), 'max:8'],
            "{$prefix}options.*.body" => ['required', 'string', 'max:2000', 'distinct:ignore_case'],
            "{$prefix}options.*.is_correct" => ['required', 'boolean'],
        ] : [];

        return [
            "{$prefix}type" => ['required', Rule::enum(QuestionType::class)],
            "{$prefix}body" => ['required', 'string', 'max:20000'],
            "{$prefix}code_language" => [Rule::requiredIf($type === QuestionType::OpenCode), 'nullable', Rule::enum(CodeLanguage::class)],
            "{$prefix}default_marks" => ['required', 'numeric', 'min:0.5', 'max:100', 'multiple_of:0.5'],
            "{$prefix}scoring_policy" => [Rule::requiredIf($type === QuestionType::MultipleChoice), 'nullable', Rule::enum(ChoiceScoringPolicy::class)],
            "{$prefix}model_answer" => [Rule::requiredIf((bool) $type?->isOpen()), 'nullable', 'string', 'max:20000'],
            "{$prefix}rubric" => ['nullable', 'string', 'max:10000'],
            "{$prefix}explanation" => ['nullable', 'string', 'max:10000'],
            "{$prefix}difficulty" => ['nullable', Rule::enum(Difficulty::class)],
            "{$prefix}tag_ids" => ['array', 'max:10'],
            "{$prefix}tag_ids.*" => ['integer', Rule::exists('tags', 'id')->where('team_id', $team->id)],
            ...$optionRules,
        ];
    }

    /**
     * The "which options are correct" rule, or null when valid.
     *
     * @param  array<int, mixed>  $options
     */
    public static function correctOptionsError(QuestionType $type, array $options): ?string
    {
        if (! $type->isChoice()) {
            return null;
        }

        $correct = count(array_filter($options, fn ($option) => is_array($option) && filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)));

        if ($type === QuestionType::SingleChoice && $correct !== 1) {
            return __('Mark exactly one option as correct.');
        }

        if ($type === QuestionType::MultipleChoice && ($correct < 2 || $correct === count($options))) {
            return __('Mark at least two correct options and leave at least one incorrect.');
        }

        return null;
    }
}
