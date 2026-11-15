<?php

namespace App\Http\Controllers;

use App\Jobs\RecordUsageEvents;
use App\Models\InconvenienceReport;
use App\Services\Usage\UsageEventSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The action log for App review (every role): batches of events from the browser, written in the background; and
 * "Inconvenient" notes with the screen and the person's last 20 actions.
 */
class UsageController extends Controller
{
    public function store(Request $request, UsageEventSanitizer $sanitizer): Response
    {
        $data = $request->validate(['events' => ['required', 'array', 'max:100'], 'events.*' => ['array'], 'session' => ['nullable', 'string', 'max:40']]);

        $events = array_values(array_filter(array_map(fn (array $e) => $sanitizer->clean($e), $data['events'])));

        if ($events !== []) {
            $user = $request->user();
            RecordUsageEvents::dispatch(currentCompany()->id, $user->id, $user->currentRole()?->value, $sanitizer->key($data['session'] ?? null), $events);
        }

        return response()->noContent();
    }

    public function inconvenient(Request $request, UsageEventSanitizer $sanitizer): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'screen' => ['nullable', 'string', 'max:80'],
            'recent' => ['array', 'max:20'],
            'recent.*' => ['array'],
        ]);

        $recent = collect($data['recent'] ?? [])
            ->map(fn (array $e) => $sanitizer->clean($e))
            ->filter()
            ->take(-20)
            ->map(fn (array $e) => [...$e, 'occurred_at' => $e['occurred_at']->toIso8601String()])
            ->values()->all();

        InconvenienceReport::create([
            'user_id' => $request->user()->id,
            'role' => $request->user()->currentRole()?->value,
            'screen' => $sanitizer->key($data['screen'] ?? null),
            'text' => trim($data['text']),
            'recent_actions' => $recent,
        ]);

        return response()->json(['message' => __('usage.inconvenient.thanks')]);
    }
}
