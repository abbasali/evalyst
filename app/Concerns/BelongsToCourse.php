<?php

namespace App\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * For course-owned models (a `team_id` column). See docs/01-architecture.md §Course scoping.
 *
 * @property int $team_id
 * @property-read Team $team
 */
trait BelongsToCourse
{
    public static function bootBelongsToCourse(): void
    {
        static::creating(function ($model) {
            if (empty($model->team_id) && $teamId = Auth::user()?->current_team_id) {
                $model->team_id = $teamId;
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForCourse(Builder $query, Team $team): void
    {
        $query->where($this->qualifyColumn('team_id'), $team->id);
    }
}
