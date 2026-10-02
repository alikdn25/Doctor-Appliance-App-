<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Payments\PaymentProvider;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
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

        // The state ties the callback to this session, company and provider (CSRF protection for OAuth).
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, [
            'state' => $state, 'company_id' => $company->id, 'provider' => $provider->key(),
        ]);

        return Inertia::location($provider->authorizationUrl($company, $state));
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
