<?php

namespace App\Http\Resources;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full question detail for the editor and the preview (includes the answer key).
 *
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'body' => $this->body,
            'code_language' => $this->code_language?->value,
            'default_marks' => (float) $this->default_marks,
            'scoring_policy' => $this->scoring_policy?->value,
            'model_answer' => $this->model_answer,
            'rubric' => $this->rubric,
            'explanation' => $this->explanation,
            'difficulty' => $this->difficulty?->value,
            'source' => $this->source->value,
            'needs_verification' => $this->needs_verification,
            'locked' => $this->isLocked(),
            'deleted' => $this->trashed(),
            'options' => $this->options->map(fn (QuestionOption $option) => [
                'id' => $option->id,
                'body' => $option->body,
                'is_correct' => $option->is_correct,
            ])->values(),
            'tags' => $this->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])->values(),
        ];
    }
}
