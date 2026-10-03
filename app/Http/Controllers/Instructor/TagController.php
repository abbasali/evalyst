<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TagController extends Controller
{
    /**
     * Create a tag (or return the existing one with the same name). JSON for inline creation.
     */
    public function store(Request $request, Team $currentTeam): JsonResponse
    {
        $name = $this->validatedName($request);

        $find = fn () => $currentTeam->tags()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        try {
            $tag = $find() ?? $currentTeam->tags()->create(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            $tag = $find(); // created concurrently by a colleague
        }

        abort_if($tag === null, 409);

        return response()->json(['id' => $tag->id, 'name' => $tag->name], $tag->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Team $currentTeam, Tag $tag): RedirectResponse
    {
        $tag->update(['name' => $this->validatedName($request, $currentTeam, $tag)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag renamed.')]);

        return back();
    }

    public function destroy(Team $currentTeam, Tag $tag): RedirectResponse
    {
        $tag->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tag deleted.')]);

        return back();
    }

    private function validatedName(Request $request, ?Team $team = null, ?Tag $tag = null): string
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $rules = ['required', 'string', 'max:40'];

        if ($team) {
            $rules[] = Rule::unique('tags', 'name')->where('team_id', $team->id)->ignore($tag?->id);
        }

        return $request->validate(['name' => $rules])['name'];
    }
}
