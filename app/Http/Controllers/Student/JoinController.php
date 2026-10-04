<?php

namespace App\Http\Controllers\Student;

use App\Actions\Attempts\JoinAssessment;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStudentSession;
use App\Models\Assessment;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JoinController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('student/Join');
    }

    public function store(Request $request, JoinAssessment $join): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:100'],
            'roll_number' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $join->handle($data['code'], $data['name'] ?? null, $data['roll_number'] ?? null);

        // Shared code: ask for the student's name and roll number next.
        if ($result instanceof Assessment) {
            if ($request->filled('name') || $request->filled('roll_number')) {
                $request->merge(['roll_number' => Student::normalizeRollNumber((string) $request->input('roll_number'))]);
                $request->validate(['name' => ['required'], 'roll_number' => ['required']]);
            }

            Inertia::flash('sharedQuiz', [
                'code' => JoinAssessment::normalize($data['code']),
                'title' => $result->title,
                'course' => $result->team->name,
            ]);

            return back();
        }

        $request->session()->regenerate();
        $request->session()->put(EnsureStudentSession::SESSION_KEY, $result->id);

        if ($result->joined_at === null) {
            $result->update(['joined_at' => now()]);
        }

        return to_route('student.landing', $result->assessment->public_id);
    }
}
