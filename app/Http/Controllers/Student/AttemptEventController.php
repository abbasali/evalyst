<?php

namespace App\Http\Controllers\Student;

use App\Enums\AttemptEventType;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Models\AttemptEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Activity the browser reports during an attempt: leaving the tab, pasting, leaving fullscreen.
 * Recorded with server timestamps; never blocks the student (D-021).
 */
class AttemptEventController extends Controller
{
    public const MAX_EVENTS_PER_ATTEMPT = 2000;

    public function store(Request $request, Attempt $attempt): JsonResponse
    {
        $data = $request->validate([
            'events' => ['required', 'array', 'max:20'],
            'events.*.type' => ['required', Rule::enum(AttemptEventType::class)->only(AttemptEventType::clientReported())],
            'events.*.position' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'events.*.length' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        if (! $attempt->isInProgress()) {
            return response()->json(['recorded' => 0], 409);
        }

        // A ceiling per attempt, so a misbehaving client can't fill the table.
        if ($attempt->events()->count() >= self::MAX_EVENTS_PER_ATTEMPT) {
            return response()->json(['recorded' => 0]);
        }

        $now = now();

        DB::transaction(function () use ($attempt, $data, $now) {
            AttemptEvent::query()->insert(array_map(function (array $event) use ($attempt, $now) {
                $meta = array_filter([
                    'position' => $event['position'] ?? null,
                    'length' => $event['length'] ?? null,
                ], fn ($value) => $value !== null);

                return [
                    'attempt_id' => $attempt->id,
                    'type' => $event['type'],
                    'occurred_at' => $now,
                    'meta' => $meta ? json_encode($meta) : null,
                    'created_at' => $now,
                ];
            }, $data['events']));

            $lost = count(array_filter($data['events'], fn (array $event) => $event['type'] === AttemptEventType::FocusLost->value));

            if ($lost > 0) {
                $attempt->increment('focus_lost_count', $lost);
            }
        });

        return response()->json(['recorded' => count($data['events'])]);
    }
}
