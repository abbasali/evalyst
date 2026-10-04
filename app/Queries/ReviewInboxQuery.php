<?php

namespace App\Queries;

use App\Enums\AnswerGradingStatus;
use App\Enums\SubmissionStatus;
use App\Models\Answer;
use App\Models\Submission;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Everything in a course waiting on an instructor: AI grades sent to review and failed
 * grading. Rows are normalised so the inbox can list them the same way.
 */
class ReviewInboxQuery
{
    public const STATUSES = ['needs_review', 'failed'];

    /**
     * @param  array{assessment?: int|string|null, question?: int|string|null, status?: string|null, reason?: string|null, group?: string|bool|null}  $filters
     */
    public function __construct(private Team $team, private array $filters = []) {}

    /**
     * @return Builder<Answer>
     */
    public function answers(): Builder
    {
        $filters = $this->filters;
        $status = in_array($filters['status'] ?? null, self::STATUSES, true) ? [$filters['status']] : self::STATUSES;

        return Answer::query()
            ->forCourse($this->team)
            ->whereIn('answers.grading_status', $status)
            ->when($filters['assessment'] ?? null, fn (Builder $query, $id) => $query->whereHas('attempt.participant', fn (Builder $query) => $query->where('assessment_id', (int) $id)))
            ->when($filters['question'] ?? null, fn (Builder $query, $id) => $query->where('assessment_question_id', (int) $id))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $reason === 'failed'
                ? $query->where('answers.grading_status', AnswerGradingStatus::Failed)
                : $query->whereJsonContains('review_reasons', $reason))
            ->when(
                filter_var($filters['group'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn (Builder $query) => $query->orderBy('assessment_question_id')->orderBy('answers.id'),
                fn (Builder $query) => $query->orderBy('answers.updated_at')->orderBy('answers.id'),
            );
    }

    /**
     * Current assignment submissions waiting on an instructor.
     *
     * @return Builder<Submission>
     */
    public function submissions(): Builder
    {
        $filters = $this->filters;
        $status = in_array($filters['status'] ?? null, self::STATUSES, true) ? [$filters['status']] : self::STATUSES;

        return Submission::query()
            ->forCourse($this->team)
            ->where('submissions.is_current', true)
            ->whereIn('submissions.status', $status)
            ->when($filters['assessment'] ?? null, fn (Builder $query, $id) => $query->whereHas('participant', fn (Builder $query) => $query->where('assessment_id', (int) $id)))
            ->when($filters['question'] ?? null, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($filters['reason'] ?? null, fn (Builder $query, string $reason) => $reason === 'failed'
                ? $query->where('submissions.status', SubmissionStatus::Failed)
                : $query->whereJsonContains('review_reasons', $reason))
            ->orderBy('submissions.updated_at');
    }

    /**
     * @return array<string, mixed>
     */
    public static function submissionRow(Submission $submission): array
    {
        $participant = $submission->participant;

        return [
            'kind' => 'submission',
            'id' => $submission->id,
            'assessment' => ['id' => $participant->assessment->id, 'title' => $participant->assessment->title],
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'repo' => str_replace('https://github.com/', '', $submission->repo_url).'@'.$submission->shortSha(),
            'status' => $submission->status->value,
            'score' => $submission->score !== null ? (float) $submission->score : null,
            'max_score' => (float) $submission->max_score,
            'reasons' => $submission->status === SubmissionStatus::Failed ? ['failed'] : ($submission->review_reasons ?? []),
            'waiting_since' => $submission->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Inbox rows with what the list needs.
     *
     * @return Builder<Answer>
     */
    public function rows(): Builder
    {
        return $this->answers()->with([
            'question:id,type,body',
            'assessmentQuestion:id,position',
            'attempt:id,participant_id',
            'attempt.participant:id,assessment_id,student_id',
            'attempt.participant.student:id,name,roll_number',
            'attempt.participant.assessment:id,title',
        ]);
    }

    /**
     * IDs in inbox order, for next/previous navigation in the detail view.
     *
     * @return list<int>
     */
    public function orderedIds(): array
    {
        return array_values(array_map('intval', $this->answers()->pluck('answers.id')->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Answer $answer): array
    {
        $participant = $answer->attempt->participant;

        return [
            'kind' => 'answer',
            'id' => $answer->id,
            'assessment' => ['id' => $participant->assessment->id, 'title' => $participant->assessment->title],
            'student' => ['name' => $participant->student->name, 'roll_number' => $participant->student->roll_number],
            'question' => [
                'position' => $answer->assessmentQuestion->position,
                'type' => $answer->question->type->value,
                'excerpt' => Str::limit(Str::squish(preg_replace('/```.*?```/s', '[code]', $answer->question->body) ?? ''), 90),
            ],
            'status' => $answer->grading_status->value,
            'ai_score' => $answer->ai_score !== null ? (float) $answer->ai_score : null,
            'max_score' => (float) $answer->max_score,
            'confidence' => $answer->ai_confidence !== null ? (float) $answer->ai_confidence : null,
            'reasons' => $answer->grading_status === AnswerGradingStatus::Failed ? ['failed'] : ($answer->review_reasons ?? []),
            'published_score' => $answer->published_at !== null && $answer->score !== null ? (float) $answer->score : null,
            'waiting_since' => $answer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The sidebar badge: items waiting in the course (cached for 30s).
     */
    public static function count(Team $team): int
    {
        return Cache::remember(self::countKey($team->id), 30, function () use ($team) {
            $inbox = new self($team);

            return $inbox->answers()->reorder()->count() + $inbox->submissions()->reorder()->count();
        });
    }

    public static function forgetCount(int $teamId): void
    {
        Cache::forget(self::countKey($teamId));
    }

    private static function countKey(int $teamId): string
    {
        return "review-inbox-count:{$teamId}";
    }
}
