<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instructor\StudentRequest;
use App\Models\Student;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function index(Request $request, Team $currentTeam): Response
    {
        $search = $request->string('search')->trim()->value();

        return Inertia::render('students/Index', [
            'students' => $currentTeam->students()
                ->search($search)
                ->orderBy('roll_number')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (Student $student) => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'roll_number' => $student->roll_number,
                    'email' => $student->email,
                ]),
            'filters' => ['search' => $search],
            'total' => $currentTeam->students()->count(),
        ]);
    }

    public function store(StudentRequest $request, Team $currentTeam): RedirectResponse
    {
        $currentTeam->students()->create($request->validated());

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
        // Once assessments exist (M05), students with participants can't be deleted.
        $student->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Student removed.')]);

        return back();
    }
}
