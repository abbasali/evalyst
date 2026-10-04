<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\PublishAssessment;
use App\Enums\AssessmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Publish, unpublish, archive and unarchive. Also serves assignments (see the `assignment` binding).
 */
class QuizStatusController extends Controller
{
    public function publish(Team $currentTeam, Assessment $quiz, PublishAssessment $publish): RedirectResponse
    {
        Gate::authorize('publish', $quiz);

        $publish->handle($quiz);

        return $this->done(__(':Noun published.', ['noun' => $quiz->noun()]));
    }

    public function unpublish(Team $currentTeam, Assessment $quiz): RedirectResponse
    {
        Gate::authorize('unpublish', $quiz);

        $quiz->update(['status' => AssessmentStatus::Draft]);

        return $this->done(__(':Noun moved back to draft.', ['noun' => $quiz->noun()]));
    }

    public function archive(Team $currentTeam, Assessment $quiz): RedirectResponse
    {
        Gate::authorize('archive', $quiz);

        $quiz->update(['status' => AssessmentStatus::Archived]);

        return $this->done(__(':Noun archived.', ['noun' => $quiz->noun()]));
    }

    public function unarchive(Team $currentTeam, Assessment $quiz): RedirectResponse
    {
        Gate::authorize('unarchive', $quiz);

        $quiz->update(['status' => AssessmentStatus::Published]);

        return $this->done(__(':Noun restored from the archive.', ['noun' => $quiz->noun()]));
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
