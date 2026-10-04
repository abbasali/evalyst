<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A question placed in an assessment, with its position and marks (answers reference it).
 *
 * @property int $id
 * @property int $assessment_id
 * @property int $question_id
 * @property int $position
 * @property string $marks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Assessment $assessment
 * @property-read Question $question
 */
#[Fillable(['assessment_id', 'question_id', 'position', 'marks'])]
class AssessmentQuestion extends Model
{
    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * Soft-deleted questions stay attached to existing assessments.
     *
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marks' => 'decimal:2',
        ];
    }
}
