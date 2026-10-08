<?php

namespace App\Support\Maps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Driving distance between two addresses (Google Distance Matrix API, GOOGLE_MAPS_SERVER_KEY). Without the key,
 * or when Google finds no route, the distance is unknown and the trip is completed by hand.
 */
class DistanceMatrix
{
    /**
     * Kilometres by road, rounded to 0.1, or null.
     */
    public function kilometres(string $from, string $to): ?float
    {
        return $this->route($from, $to)['km'] ?? null;
    }

    /**
     * Road distance and the addresses Google matched (a GPS point "49.2,-123.1" comes back as a street address).
     *
     * @return array{km: float, from: string|null, to: string|null}|null
     */
    public function route(string $from, string $to): ?array
    {
        if (! Geocoder::enabled() || trim($from) === '' || trim($to) === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                'origins' => $from,
                'destinations' => $to,
                'mode' => 'driving',
                'units' => 'metric',
                'key' => config('services.google_maps.server_key'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Distance lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        $metres = $response->json('rows.0.elements.0.distance.value');

        if (! $response->ok() || $response->json('status') !== 'OK' || $response->json('rows.0.elements.0.status') !== 'OK' || ! is_numeric($metres)) {
            Log::info('Distance not found', ['status' => $response->json('status'), 'element' => $response->json('rows.0.elements.0.status')]);

            return null;
        }

        return [
            'km' => round($metres / 1000, 1),
            'from' => $response->json('origin_addresses.0') ?: null,
            'to' => $response->json('destination_addresses.0') ?: null,
        ];
    }
}
