<?php

namespace App\Http\Controllers;

use App\Support\AppVersion;
use Illuminate\Http\Response;

/**
 * The installed app's service worker (resources/pwa/sw.js) with the running version written into it. The version
 * changes with every deploy and build, so phones pick up the new worker, which deletes the old caches.
 */
class ServiceWorkerController extends Controller
{
    public const PLACEHOLDER = '__SW_VERSION__';

    public function __invoke(): Response
    {
        $script = str_replace(
            self::PLACEHOLDER,
            AppVersion::current(),
            (string) file_get_contents(resource_path('pwa/sw.js')),
        );

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // The browser must always ask for the newest worker.
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
