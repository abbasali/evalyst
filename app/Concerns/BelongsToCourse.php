<?php

namespace App\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        // Fill team_id from the `{current_team}` route parameter, never from the user's
        // "current course" (which can differ, e.g. on settings routes or in queued jobs).
        static::creating(function ($model) {
            $team = request()->route('current_team');

            if (empty($model->team_id) && $team instanceof Team) {
                $model->team_id = $team->id;
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
