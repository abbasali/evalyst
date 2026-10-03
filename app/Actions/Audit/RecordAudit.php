<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAudit
{
    /**
     * Record a manual change (grade override, attempt reset, ...) against its course.
     *
     * @param  array<string, mixed>  $changes  Typically ['before' => [...], 'after' => [...]].
     */
    public function handle(User $user, Model $subject, string $action, array $changes = [], ?string $note = null): AuditLog
    {
        $teamId = $subject instanceof Team
            ? $subject->getKey()
            : ($subject->getAttribute('team_id') ?? $subject->getAttribute('team')?->getKey());

        return AuditLog::create([
            'team_id' => $teamId,
            'user_id' => $user->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'changes' => $changes ?: null,
            'note' => $note,
        ]);
    }
}
