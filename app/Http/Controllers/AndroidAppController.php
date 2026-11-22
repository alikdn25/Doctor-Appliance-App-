<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Support\AndroidRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Android app (owner's task of November 22): the download page (Owner only) and the latest version for the app's
 * "Update available" banner. The APK itself is served by PaidController::androidApk (public: the app needs a sign-in).
 */
class AndroidAppController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless($request->user()->hasRole(UserRole::Owner), 403);
        $release = AndroidRelease::current();

        return Inertia::render('android/index', [
            'release' => $release === null ? null : [
                ...$release,
                'built_at' => $release['built_at'] !== null
                    ? $release['built_at']->setTimezone(currentCompany()->timezone)->toIso8601String()
                    : null,
            ],
        ]);
    }

    /**
     * The newest APK on the server: the app compares version_code with its own on start.
     */
    public function latest(): JsonResponse
    {
        $release = AndroidRelease::current();

        return response()->json($release === null ? ['version_code' => 0] : [
            'version_code' => $release['version_code'],
            'version_name' => $release['version_name'],
            'url' => $release['url'],
        ])->header('Cache-Control', 'no-store');
    }
}
