<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Assessments\AddParticipants;
use App\Enums\AccessMode;
use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Instructor\Concerns\PresentsQuiz;
use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Team;
use App\Support\AccessCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuizAccessController extends Controller
{
    use PresentsQuiz;

    public function show(Team $currentTeam, Assessment $quiz): Response
    {
        $participants = $quiz->participants()
            ->with(['student', 'attempt:id,participant_id,status'])
            ->get()
            ->sortBy(fn (Participant $participant) => $participant->student->roll_number, SORT_NATURAL)
            ->values();

        return Inertia::render('quizzes/Access', [
            ...$this->quizShell($currentTeam, $quiz),
            'sharedCode' => $quiz->shared_code,
            'joinUrl' => url('/join'),
            'participants' => $participants->map(fn (Participant $participant) => [
                'id' => $participant->id,
                'student_id' => $participant->student_id,
                'name' => $participant->student->name,
                'roll_number' => $participant->student->roll_number,
                'access_code' => $participant->access_code,
                'status' => $this->status($participant),
                'joined_at' => $participant->joined_at?->toIso8601String(),
            ]),
            // The roster picker (roster mode only); a course roster is small enough to send whole.
            'roster' => $quiz->access_mode === AccessMode::Roster
                ? $currentTeam->students()->orderBy('roll_number')->get(['id', 'name', 'roll_number'])
                : [],
        ]);
    }

    public function storeParticipants(Request $request, Team $currentTeam, Assessment $quiz, AddParticipants $add): RedirectResponse
    {
        Gate::authorize('manageAccess', $quiz);
        $this->ensureMode($quiz, AccessMode::Roster);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'student_ids.*' => ['integer', Rule::exists('students', 'id')->where('team_id', $currentTeam->id)],
        ]);

        $count = $add->handle($quiz, array_values(array_map('intval', $data['student_ids'])));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count student added.|:count students added.', $count)]);

        return back();
    }

    public function regenerateCode(Team $currentTeam, Assessment $quiz, Participant $participant): RedirectResponse
    {
        Gate::authorize('manageAccess', $quiz);
        $this->ensureMode($quiz, AccessMode::Roster);

        $participant->update(['access_code' => AccessCode::generate(AccessCode::ROSTER_LENGTH)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New code for :name. The old one no longer works.', ['name' => $participant->student->name])]);

        return back();
    }

    public function destroyParticipant(Team $currentTeam, Assessment $quiz, Participant $participant): RedirectResponse
    {
        Gate::authorize('manageAccess', $quiz);
        $this->ensureMode($quiz, AccessMode::Roster);
        Gate::authorize('delete', $participant);

        if (! $quiz->isDraft() && $quiz->participants()->count() <= 1) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('A published quiz needs at least one student. Move it back to draft first.')]);

            return back();
        }

        $participant->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from the quiz.', ['name' => $participant->student->name])]);

        return back();
    }

    public function rotateSharedCode(Team $currentTeam, Assessment $quiz): RedirectResponse
    {
        Gate::authorize('manageAccess', $quiz);
        $this->ensureMode($quiz, AccessMode::SharedCode);

        $quiz->update(['shared_code' => AccessCode::generate(AccessCode::SHARED_LENGTH)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New join code issued. The old code no longer works for new joins.')]);

        return back();
    }

    public function printCodes(Team $currentTeam, Assessment $quiz): Response
    {
        $this->ensureMode($quiz, AccessMode::Roster);

        return Inertia::render('quizzes/CodesPrint', [
            'quiz' => ['id' => $quiz->id, 'title' => $quiz->title],
            'courseName' => $currentTeam->name,
            'joinUrl' => url('/join'),
            'cards' => $this->codeRows($quiz),
        ]);
    }

    public function downloadCodes(Team $currentTeam, Assessment $quiz): StreamedResponse
    {
        $this->ensureMode($quiz, AccessMode::Roster);

        $rows = $this->codeRows($quiz);
        $filename = Str::slug($quiz->title).'-codes.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['roll_number', 'name', 'code'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, [$this->csvSafe($row['roll_number']), $this->csvSafe($row['name']), $row['access_code']], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<array{name: string, roll_number: string, access_code: string|null}>
     */
    private function codeRows(Assessment $quiz): array
    {
        return array_values($quiz->participants()->with('student')->get()
            ->sortBy(fn (Participant $participant) => $participant->student->roll_number, SORT_NATURAL)
            ->map(fn (Participant $participant) => [
                'name' => $participant->student->name,
                'roll_number' => $participant->student->roll_number,
                'access_code' => $participant->access_code,
            ])
            ->all());
    }

    private function status(Participant $participant): string
    {
        return match ($participant->attempt?->status) {
            null => 'not_started',
            AttemptStatus::InProgress => 'in_progress',
            default => 'submitted',
        };
    }

    private function ensureMode(Assessment $quiz, AccessMode $mode): void
    {
        abort_unless($quiz->access_mode === $mode, 422, __('This quiz uses a different access mode.'));
    }

    /**
     * Stop spreadsheet apps from treating a student's name as a formula.
     */
    private function csvSafe(string $value): string
    {
        return in_array(substr(ltrim($value, ' '), 0, 1), ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
