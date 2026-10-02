<?php

namespace App\Http\Controllers;

use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentProviders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhooks of online payment providers. No session or CSRF: each provider verifies its own signature.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentProviders $providers): Response
    {
        $found = $providers->find($provider) ?? abort(404);

        try {
            $found->handleWebhook($request);
        } catch (InvalidWebhookSignature) {
            abort(401);
        }

        return response()->noContent();
    }
}
