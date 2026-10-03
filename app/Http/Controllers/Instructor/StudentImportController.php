<?php

namespace App\Http\Controllers\Instructor;

use App\Actions\Students\ImportStudents;
use App\Actions\Students\PreviewStudentImport;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StudentImportController extends Controller
{
    /**
     * Parse the uploaded CSV and flash a row-by-row preview. The rows are kept in the
     * cache (one pending import per instructor and course) until confirmed.
     */
    public function preview(Request $request, Team $currentTeam, PreviewStudentImport $preview): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);

        $rows = $preview->handle($currentTeam, $request->file('file'));
        $token = Str::random(32);

        Cache::put($this->cacheKey($request, $currentTeam), ['token' => $token, 'rows' => $rows], now()->addMinutes(30));

        Inertia::flash('importPreview', ['token' => $token, 'rows' => $rows]);

        return back();
    }

    public function confirm(Request $request, Team $currentTeam, ImportStudents $import): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $pending = Cache::pull($this->cacheKey($request, $currentTeam));

        if (! is_array($pending) || ! hash_equals($pending['token'], $request->string('token')->value())) {
            throw ValidationException::withMessages(['file' => __('This import preview has expired. Please upload the file again.')]);
        }

        $counts = $import->handle($currentTeam, $pending['rows']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':created added, :updated updated, :unchanged unchanged, :skipped skipped.', $counts)]);

        return back();
    }

    private function cacheKey(Request $request, Team $team): string
    {
        return "student_import:{$request->user()->id}:{$team->id}";
    }
}
