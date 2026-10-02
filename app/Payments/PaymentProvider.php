<?php

namespace App\Payments;

use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * An online payment provider a company can connect (SPEC §7.6): Square first, then Stripe (for the countries
 * Square does not serve), later others. Invoice logic never depends on a specific provider: a provider creates
 * a way to pay and reports payments back through App\Actions\Billing\RecordPayment::fromProvider().
 *
 * Each company connects its own account (OAuth); money goes straight to the company.
 * Implementations are registered in config/payments.php.
 */
interface PaymentProvider
{
    /**
     * Stable key stored on the company and on payments, e.g. "square".
     */
    public function key(): string;

    /**
     * Name shown in settings, e.g. "Square".
     */
    public function label(): string;

    /**
     * Whether the provider serves companies in the company's country (and is configured on this installation).
     */
    public function isAvailableFor(Company $company): bool;

    /**
     * Whether the company has finished connecting its own account (e.g. OAuth done).
     */
    public function isConnected(Company $company): bool;

    /**
     * What to show about the connected account in settings (name, location, currency), or null.
     *
     * @return array{account: string|null, location: string|null, currency: string|null, connected_at: string|null}|null
     */
    public function connectionSummary(Company $company): ?array;

    /**
     * Where to send the Owner to authorize the company's account. $state is checked on return.
     */
    public function authorizationUrl(Company $company, string $state): string;

    /**
     * Finishes the OAuth flow with the provider's callback query (code, error …) and stores the connection.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws PaymentProviderException
     */
    public function connect(Company $company, array $query, User $user): void;

    /**
     * Revokes the company's authorization (best effort) and forgets its tokens.
     */
    public function disconnect(Company $company): void;

    /**
     * A link where the customer pays the given amount (minor units, in the document currency) of an invoice
     * online or on the technician's screen (QR code), or the deposit of an estimate they approved online.
     *
     * @throws PaymentProviderException
     */
    public function createPaymentLink(Estimate|Invoice $document, int $amount): PaymentLink;

    /**
     * Retires a link that is no longer wanted (best effort).
     */
    public function cancelPaymentLink(Company $company, string $providerReference): void;

    /**
     * Verifies and handles a webhook delivery. Records payments through RecordPayment::fromProvider().
     *
     * @throws InvalidWebhookSignature
     */
    public function handleWebhook(Request $request): void;
}
