<?php

namespace App\Actions\Questions;

use App\Enums\ChoiceScoringPolicy;
use App\Enums\QuestionSource;
use App\Enums\QuestionType;
use App\Models\QuestionGeneration;
use App\Models\User;
use App\Support\QuestionRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AcceptGeneratedQuestions
{
    public function __construct(private SaveQuestion $save) {}

    /**
     * Add selected (possibly edited) drafts to the question bank.
     *
     * @param  list<array<string, mixed>>  $submitted  Drafts as edited by the instructor, each with its `uid`.
     * @return int Number of questions created.
     */
    public function handle(QuestionGeneration $generation, User $user, array $submitted): int
    {
        $team = $generation->team;
        $this->validate($generation, $submitted);

        return DB::transaction(function () use ($generation, $user, $submitted, $team) {
            $generation = QuestionGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $drafts = collect($generation->drafts ?? [])->keyBy('uid');

            foreach ($submitted as $draft) {
                $stored = $drafts->get($draft['uid']);

                if (! $stored || ($stored['accepted'] ?? false)) {
                    throw ValidationException::withMessages(['drafts' => __('Some of these drafts were already added. Refresh the page.')]);
                }

                $edited = $this->keyFields($draft) !== $this->keyFields($stored);
                $disputed = ($stored['verification']['status'] ?? null) === 'disputed';

                $this->save->handle($team, $user, [
                    ...Arr::only($draft, ['type', 'body', 'code_language', 'default_marks', 'scoring_policy', 'model_answer', 'rubric', 'explanation', 'difficulty', 'options']),
                    'needs_verification' => $disputed && ! $edited,
                    'tag_ids' => $generation->tag_ids ?? [],
                ], createAttributes: [
                    'source' => QuestionSource::Ai,
                    'question_generation_id' => $generation->id,
                ]);

                $drafts->put($draft['uid'], [...$stored, 'accepted' => true]);
            }

            $generation->update([
                'drafts' => $drafts->values()->all(),
                'accepted_count' => $generation->accepted_count + count($submitted),
            ]);

            return count($submitted);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $submitted
     */
    private function validate(QuestionGeneration $generation, array $submitted): void
    {
        $rules = ['drafts' => ['required', 'array', 'min:1', 'max:30'], 'drafts.*.uid' => ['required', 'string']];

        foreach ($submitted as $index => $draft) {
            $type = QuestionType::tryFrom((string) ($draft['type'] ?? ''));
            $rules = [...$rules, ...QuestionRules::rules($generation->team, $type, "drafts.{$index}.")];
        }

        $validator = Validator::make(['drafts' => $submitted], $rules);

        $validator->after(function ($validator) use ($submitted) {
            foreach ($submitted as $index => $draft) {
                $type = QuestionType::tryFrom((string) ($draft['type'] ?? ''));

                if ($type && ($error = QuestionRules::correctOptionsError($type, (array) ($draft['options'] ?? [])))) {
                    $validator->errors()->add("drafts.{$index}.options", $error);
                }
            }
        });

        $validator->validate();
    }

    /**
     * The fields whose change means the instructor fixed the draft themselves.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function keyFields(array $draft): array
    {
        return [
            'body' => trim((string) ($draft['body'] ?? '')),
            'options' => array_map(fn ($option) => [trim((string) $option['body']), filter_var($option['is_correct'], FILTER_VALIDATE_BOOLEAN)], (array) ($draft['options'] ?? [])),
        ];
    }

    /**
     * Turn a stored draft into question form fields (used to pre-fill the review UI).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function toFormFields(array $draft): array
    {
        return [
            'uid' => $draft['uid'],
            'type' => $draft['type'],
            'body' => $draft['body'],
            'code_language' => $draft['code_language'],
            'default_marks' => $draft['suggested_marks'],
            'scoring_policy' => $draft['type'] === 'multiple_choice' ? ChoiceScoringPolicy::AllOrNothing->value : null,
            'model_answer' => $draft['model_answer'],
            'rubric' => $draft['rubric'],
            'explanation' => $draft['explanation'],
            'difficulty' => $draft['difficulty'],
            'options' => $draft['options'],
        ];
    }
}
