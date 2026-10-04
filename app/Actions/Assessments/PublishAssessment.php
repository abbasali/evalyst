<?php

namespace App\Actions\Assessments;

use App\Enums\AccessMode;
use App\Enums\AssessmentStatus;
use App\Models\Assessment;
use App\Support\AccessCode;
use Illuminate\Validation\ValidationException;

class PublishAssessment
{
    /**
     * The publish checklist shown in the UI. Every item must pass before publishing.
     *
     * @return list<array{key: string, label: string, ok: bool}>
     */
    public function checklist(Assessment $assessment): array
    {
        if ($assessment->isAssignment()) {
            return $this->assignmentChecklist($assessment);
        }

        $questions = $assessment->assessmentQuestions()->with('question')->get();

        $checks = [
            ['key' => 'questions', 'label' => __('At least one question'), 'ok' => $questions->isNotEmpty()],
            ['key' => 'marks', 'label' => __('Every question has marks above 0'), 'ok' => $questions->every(fn ($item) => (float) $item->marks > 0)],
        ];

        if ($questions->contains(fn ($item) => $item->question->trashed())) {
            $checks[] = ['key' => 'deleted', 'label' => __('Remove questions deleted from the bank'), 'ok' => false];
        }

        $checks[] = ['key' => 'duration', 'label' => __('Duration is set'), 'ok' => (int) $assessment->duration_minutes > 0];
        $checks[] = [
            'key' => 'schedule',
            'label' => __('Closing time is in the future (and after the opening time)'),
            'ok' => $assessment->closes_at->isFuture() && ($assessment->opens_at === null || $assessment->opens_at->lt($assessment->closes_at)),
        ];

        $checks[] = $assessment->access_mode === AccessMode::Roster
            ? ['key' => 'access', 'label' => __('At least one student added'), 'ok' => $assessment->participants()->exists()]
            : ['key' => 'access', 'label' => __('Shared join code ready'), 'ok' => true];

        return $checks;
    }

    /**
     * @return list<array{key: string, label: string, ok: bool}>
     */
    private function assignmentChecklist(Assessment $assessment): array
    {
        $rules = $assessment->rules()->get();

        return [
            ['key' => 'statement', 'label' => __('Problem statement written'), 'ok' => trim((string) $assessment->instructions) !== ''],
            ['key' => 'rules', 'label' => __('At least one grading rule'), 'ok' => $rules->isNotEmpty()],
            ['key' => 'marks', 'label' => __('Total marks above 0'), 'ok' => $rules->sum(fn ($rule) => (float) $rule->marks) > 0],
            [
                'key' => 'schedule',
                'label' => __('Deadline is in the future (and after the opening time)'),
                'ok' => $assessment->closes_at->isFuture() && ($assessment->opens_at === null || $assessment->opens_at->lt($assessment->closes_at)),
            ],
            $assessment->access_mode === AccessMode::Roster
                ? ['key' => 'access', 'label' => __('At least one student added'), 'ok' => $assessment->participants()->exists()]
                : ['key' => 'access', 'label' => __('Shared join code ready'), 'ok' => true],
        ];
    }

    /**
     * @throws ValidationException listing every failed check under `publish`.
     */
    public function handle(Assessment $assessment): Assessment
    {
        $failed = array_values(array_filter($this->checklist($assessment), fn (array $check) => ! $check['ok']));

        if ($failed !== []) {
            throw ValidationException::withMessages(['publish' => array_column($failed, 'label')]);
        }

        if ($assessment->access_mode === AccessMode::SharedCode) {
            $assessment->shared_code ??= AccessCode::generate(AccessCode::SHARED_LENGTH);
        }

        $assessment->status = AssessmentStatus::Published;
        $assessment->save();

        return $assessment;
    }
}
