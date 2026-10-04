<?php

namespace App\Models;

use App\Concerns\HasAuditLogs;
use App\Enums\AnswerGradingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $attempt_id
 * @property int $assessment_question_id
 * @property int $question_id
 * @property list<int>|null $selected_option_ids
 * @property string|null $text_answer
 * @property string|null $code_answer
 * @property bool $flagged
 * @property Carbon|null $answered_at
 * @property string $max_score
 * @property string|null $score
 * @property AnswerGradingStatus $grading_status
 * @property string|null $ai_score
 * @property string|null $ai_feedback
 * @property string|null $ai_confidence
 * @property list<array{criterion: string, awarded: float, max: float, note: string}>|null $ai_breakdown
 * @property list<string>|null $ai_flags
 * @property list<string>|null $review_reasons
 * @property string|null $grading_error
 * @property string|null $feedback
 * @property int|null $graded_by
 * @property Carbon|null $published_at
 * @property-read int $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Attempt $attempt
 * @property-read AssessmentQuestion $assessmentQuestion
 * @property-read Question $question
 */
#[Fillable([
    'attempt_id', 'assessment_question_id', 'question_id', 'selected_option_ids', 'text_answer', 'code_answer',
    'flagged', 'answered_at', 'max_score', 'score', 'grading_status', 'ai_score', 'ai_feedback', 'ai_confidence',
    'ai_breakdown', 'ai_flags', 'review_reasons', 'grading_error', 'feedback', 'graded_by', 'published_at',
])]
class Answer extends Model
{
    use HasAuditLogs;

    /**
     * @return BelongsTo<Attempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    /**
     * @return BelongsTo<AssessmentQuestion, $this>
     */
    public function assessmentQuestion(): BelongsTo
    {
        return $this->belongsTo(AssessmentQuestion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
    }

    /**
     * The course, through the attempt (used by RecordsAiRun and RecordAudit).
     *
     * @return Attribute<int, never>
     */
    protected function teamId(): Attribute
    {
        return Attribute::get(fn () => $this->attempt->participant->assessment->team_id);
    }

    /**
     * Open answers with no text and no code are scored 0 without calling the AI.
     */
    public function isBlank(): bool
    {
        return trim((string) $this->text_answer) === '' && trim((string) $this->code_answer) === '';
    }

    /**
     * @param  Builder<Answer>  $query
     */
    public function scopeForCourse(Builder $query, Team $team): void
    {
        $query->whereHas('attempt.participant.assessment', fn (Builder $query) => $query->where('team_id', $team->id));
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selected_option_ids' => 'array',
            'flagged' => 'boolean',
            'answered_at' => 'datetime',
            'max_score' => 'decimal:2',
            'score' => 'decimal:2',
            'grading_status' => AnswerGradingStatus::class,
            'ai_score' => 'decimal:2',
            'ai_confidence' => 'decimal:2',
            'ai_breakdown' => 'array',
            'ai_flags' => 'array',
            'review_reasons' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
