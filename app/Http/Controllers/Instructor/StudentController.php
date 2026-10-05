<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\AssessmentStatus;
use App\Enums\AssessmentType;
use App\Grading\ParticipantScore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StudentRequest;
use App\Models\Participant;
use App\Models\Student;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response|RedirectResponse
    {
        $search = $request->string('search')->trim()->value();

        $assessments = $currentTeam->assessments()
            ->whereIn('status', [AssessmentStatus::Published, AssessmentStatus::Archived])
            ->pluck('type', 'id');

        $students = $currentTeam->students()
            ->search($search)
            ->with(['participants' => fn ($query) => $query->whereIn('assessment_id', $assessments->keys())->with([
                'attempt:id,participant_id,status,score',
                'currentSubmission:id,participant_id,status,score',
            ])])
            ->orderBy('roll_number')
            ->paginate(25)
            ->withQueryString();

        // e.g. after deleting the last row of the last page
        if ($students->isEmpty() && $students->currentPage() > 1) {
            return redirect($students->url($students->lastPage()));
        }

        return Inertia::render('students/Index', [
            'students' => $students->through(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'roll_number' => $student->roll_number,
                'email' => $student->email,
                'total_score' => $this->totalScore($student, $assessments),
            ]),
            'filters' => ['search' => $search],
            'total' => $currentTeam->students()->count(),
        ]);
    }

    /**
     * Sum of finished grades (released or not), or null when nothing is graded yet.
     *
     * @param  Collection<int, AssessmentType>  $assessments
     */
    private function totalScore(Student $student, Collection $assessments): ?float
    {
        $scores = $student->participants
            ->map(fn (Participant $participant) => ParticipantScore::graded($participant, $assessments[$participant->assessment_id] === AssessmentType::Assignment))
            ->reject(fn (?float $score) => $score === null);

        return $scores->isEmpty() ? null : round($scores->sum(), 2);
    }

    public function store(StudentRequest $request, Team $currentTeam): RedirectResponse
    {
        try {
            $currentTeam->students()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['roll_number' => __('Another student in this course already has this roll number.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student added.')]);

        return back();
    }

    public function update(StudentRequest $request, Team $currentTeam, Student $student): RedirectResponse
    {
        $student->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student updated.')]);

        return back();
    }

    public function destroy(Team $currentTeam, Student $student): RedirectResponse
    {
        if ($student->participants()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __(':name has taken part in an assessment and can\'t be deleted.', ['name' => $student->name])]);

            return back();
        }

        $student->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student removed.')]);

        return back();
    }
}
