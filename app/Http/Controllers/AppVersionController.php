<?php

namespace App\Http\Controllers;

use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;

/**
 * The running version, checked by the app when it opens and every 30 minutes (never cached).
 */
class AppVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['version' => AppVersion::current()], 200, [
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
