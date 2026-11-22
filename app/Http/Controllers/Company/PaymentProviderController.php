<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Payments\PaymentProvider;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
use App\Payments\Square\SquareProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting a company's own account at a payment provider (OAuth) and disconnecting it. Owner only.
 */
class PaymentProviderController extends Controller
{
    private const STATE_KEY = 'payment_provider_oauth';

    public function connect(Request $request, string $provider, PaymentProviders $providers): Response
    {
        $company = currentCompany();
        Gate::authorize('managePayments', $company);
        $provider = $this->available($providers, $provider);

        // "Import from Square" asks for the extra read permissions and comes back to the import screen.
        $import = $request->query('for') === 'import' && $provider->key() === 'square';

        // The state ties the callback to this session, company and provider (CSRF protection for OAuth).
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, [
            'state' => $state, 'company_id' => $company->id, 'provider' => $provider->key(), 'import' => $import,
        ]);

        return Inertia::location($provider->authorizationUrl($company, $state, $import ? (array) config('payments.square.import_scopes') : []));
    }

    public function callback(Request $request, string $provider, PaymentProviders $providers): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('managePayments', $company);
        $provider = $this->available($providers, $provider);

        $expected = $request->session()->pull(self::STATE_KEY);
        $valid = is_array($expected)
            && hash_equals((string) $expected['state'], (string) $request->query('state'))
            && $expected['company_id'] === $company->id
            && $expected['provider'] === $provider->key();

        if (! $valid) {
            return $this->back('error', __('payments.connect.invalid_state'));
        }

        try {
            $provider->connect($company, $request->query(), $request->user());
        } catch (PaymentProviderException $e) {
            return $this->back('error', $e->getMessage());
        }

        if ($expected['import'] ?? false) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('square_import.access_granted')]);

            return to_route('square-import.show');
        }

        return $this->back('success', __('payments.connect.connected', ['provider' => $provider->label()]));
    }

    public function disconnect(string $provider, PaymentProviders $providers): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('managePayments', $company);
        $found = $providers->find($provider) ?? abort(404);

        $found->disconnect($company);

        return $this->back('success', __('payments.connect.disconnected', ['provider' => $found->label()]));
    }

    /**
     * Settings → Payments: the Square locations to choose from (card reader payments go to the chosen one).
     */
    public function locations(SquareProvider $square): JsonResponse
    {
        Gate::authorize('managePayments', currentCompany());

        try {
            return response()->json([
                'locations' => $square->locations(currentCompany()),
                // An older connection without the card reader's permissions: "Reconnect Square".
                'reconnect' => $square->missingReaderScopes(currentCompany()) !== [],
                'reconnect_url' => route('payment-providers.connect', 'square'),
            ]);
        } catch (PaymentProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function location(Request $request, SquareProvider $square): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('managePayments', $company);
        $id = (string) $request->validate(['location_id' => ['required', 'string', 'max:100']])['location_id'];

        try {
            $square->setLocation($company, $id);
        } catch (PaymentProviderException $e) {
            return $this->back('error', $e->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('charges.settings.location_saved')]);

        return to_route('company.settings.section', 'payments');
    }

    private function available(PaymentProviders $providers, string $key): PaymentProvider
    {
        $provider = $providers->find($key);

        abort_if($provider === null || ! $provider->isAvailableFor(currentCompany()), 404);

        return $provider;
    }

    private function back(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('company.settings.edit');
    }
}
