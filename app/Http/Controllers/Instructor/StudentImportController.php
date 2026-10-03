<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Students\ImportStudents;
use App\Actions\Students\PreviewStudentImport;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class StudentImportController extends Controller
{
    /**
     * Parse the uploaded CSV and flash a row-by-row preview (kept in the session).
     */
    public function preview(Request $request, Team $currentTeam, PreviewStudentImport $preview): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);

        $rows = $preview->handle($currentTeam, $request->file('file'));
        $token = Str::random(32);

        $request->session()->put("student_import.{$token}", ['team_id' => $currentTeam->id, 'rows' => $rows]);

        Inertia::flash('importPreview', ['token' => $token, 'rows' => $rows]);

        return back();
    }

    public function confirm(Request $request, Team $currentTeam, ImportStudents $import): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $preview = $request->session()->pull("student_import.{$request->string('token')}");

        abort_if(! is_array($preview) || $preview['team_id'] !== $currentTeam->id, 410, __('This import preview has expired. Please upload the file again.'));

        $counts = $import->handle($currentTeam, $preview['rows']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':created added, :updated updated, :skipped skipped.', $counts)]);

        return back();
    }
}
