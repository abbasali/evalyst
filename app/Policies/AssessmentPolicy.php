<?php

namespace App\Policies;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Course membership is enforced by the route group; these rules protect a quiz's state
 * (archived = read-only, and no score-changing edits once students have started).
 */
class AssessmentPolicy
{
    public function update(User $user, Assessment $assessment): Response
    {
        return $assessment->isArchived()
            ? Response::deny(__('Archived :nouns are read-only. Unarchive it first.', ['noun' => $assessment->noun()]))
            : Response::allow();
    }

    /**
     * Adding, removing or reordering questions, and changing their marks.
     */
    public function editQuestions(User $user, Assessment $assessment): Response
    {
        if ($assessment->hasAttempts()) {
            return Response::deny(__('Locked because students have started.'));
        }

        return $this->update($user, $assessment);
    }

    public function manageAccess(User $user, Assessment $assessment): Response
    {
        return $this->update($user, $assessment);
    }

    public function publish(User $user, Assessment $assessment): Response
    {
        return $assessment->isDraft()
            ? Response::allow()
            : Response::deny(__('Only a draft can be published.'));
    }

    public function unpublish(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isPublished()) {
            return Response::deny(__('Only a published :noun can be unpublished.', ['noun' => $assessment->noun()]));
        }

        return $assessment->hasAttempts()
            ? Response::deny(__('Students have started or submitted, so it can\'t go back to draft.'))
            : Response::allow();
    }

    public function archive(User $user, Assessment $assessment): Response
    {
        if (! $assessment->isPublished()) {
            return Response::deny(__('Only a published :noun can be archived.', ['noun' => $assessment->noun()]));
        }

        return $assessment->isClosed() || ! $assessment->hasAttempts()
            ? Response::allow()
            : Response::deny(__('Students are still working on this :noun. Archive it after it closes.', ['noun' => $assessment->noun()]));
    }

    public function unarchive(User $user, Assessment $assessment): Response
    {
        return $assessment->isArchived()
            ? Response::allow()
            : Response::deny(__('This :noun isn\'t archived.', ['noun' => $assessment->noun()]));
    }

    public function delete(User $user, Assessment $assessment): Response
    {
        return $assessment->isDraft() && ! $assessment->participants()->exists()
            ? Response::allow()
            : Response::deny(__('Only a draft without participants can be deleted. Archive it instead.'));
    }
}
