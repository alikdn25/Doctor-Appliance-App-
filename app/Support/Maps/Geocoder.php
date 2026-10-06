<?php

namespace App\Support\Maps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side address lookup (Google Geocoding API) for addresses typed by hand, so they still appear on the
 * calendar map. Needs GOOGLE_MAPS_SERVER_KEY (an IP-restricted key; the browser key is referrer-restricted and
 * does not work from the server). Without the key nothing is looked up.
 */
class Geocoder
{
    public static function enabled(): bool
    {
        return filled(config('services.google_maps.server_key'));
    }

    /**
     * @return array{lat: float, lng: float, place_id: string|null}|null
     */
    public function locate(string $address, string $country): ?array
    {
        if (! self::enabled() || trim($address) === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address,
                'components' => 'country:'.strtoupper($country),
                'key' => config('services.google_maps.server_key'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Geocoding failed', ['error' => $e->getMessage()]);

            return null;
        }

        $result = $response->json('results.0');

        if (! $response->ok() || $response->json('status') !== 'OK' || ! is_array($result)) {
            if (! in_array($response->json('status'), ['ZERO_RESULTS', 'OK'], true)) {
                Log::warning('Geocoding refused', ['status' => $response->json('status'), 'error' => $response->json('error_message')]);
            }

            return null;
        }

        return [
            'lat' => (float) data_get($result, 'geometry.location.lat'),
            'lng' => (float) data_get($result, 'geometry.location.lng'),
            'place_id' => data_get($result, 'place_id'),
        ];
    }
}
