<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Jobs\NearbyWindows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Windows by area for Book customer and Quick booking (owner's task of November 19): the days of the next week that
 * already have a job near the chosen address, with "Nearby: job in {city}, {N} km". The form puts those windows
 * first. No address (or no map position): no days, the usual order.
 */
class NearbyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('create', ServiceJob::class);
        $data = $request->validate([
            'property_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:300'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $property = isset($data['property_id']) ? Property::query()->find($data['property_id']) : null;
        if ($property !== null) {
            Gate::authorize('view', $property->customer);
        }

        // A Google Places pick already has its position.
        $point = isset($data['lat'], $data['lng']) && $property === null
            ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']]
            : NearbyWindows::point($property, $data['address'] ?? null);

        return response()->json(['days' => NearbyWindows::days(currentCompany(), $point)]);
    }
}
