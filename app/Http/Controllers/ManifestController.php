<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Web app manifest: lets technicians install the app on their phone's home screen.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'description' => __('pwa.description'),
            // Technicians land on their visits for the day.
            'start_url' => '/my-jobs',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#0E7490',
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
