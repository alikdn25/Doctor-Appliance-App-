<?php

namespace App\Http\Controllers;

use App\Support\Push\PushActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A tap on a button of a push notification (sent by the service worker) or of the same notification in the bell list.
 * The token belongs to one person and works once; the tap is the confirmation. No CSRF token: the service worker
 * cannot read the cookie, and the 48-character token sent only to this person's devices stands in for it.
 */
class PushActionController extends Controller
{
    public function __invoke(Request $request, string $token, string $action): JsonResponse
    {
        return response()->json(['message' => PushActions::run($token, $action, $request->user())]);
    }
}
