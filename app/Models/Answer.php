<?php

namespace App\Models;

use App\Enums\AnswerGradingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Attempt $attempt
 * @property-read AssessmentQuestion $assessmentQuestion
 * @property-read Question $question
 */
#[Fillable([
    'attempt_id', 'assessment_question_id', 'question_id', 'selected_option_ids', 'text_answer', 'code_answer',
    'flagged', 'answered_at', 'max_score', 'score', 'grading_status',
])]
class Answer extends Model
{
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
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
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
        ];
    }
}
