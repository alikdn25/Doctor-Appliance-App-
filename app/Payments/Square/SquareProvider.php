<?php

namespace App\Payments\Square;

use App\Actions\Billing\RecordPayment;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Charge;
use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\User;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentLink;
use App\Payments\PaymentProvider;
use App\Payments\PaymentProviderException;
use App\Services\AuditLogger;
use App\Support\Billing\Money;
use App\Support\Payments\ChargeCheckout;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Square, the first online payment provider (SPEC §7.6).
 *
 * - Each company connects its own Square account by OAuth; payments go to its main location.
 * - The customer pays the invoice balance through a Square payment link (also shown as a QR code).
 * - payment.created / payment.updated webhooks record completed payments on the invoice, once per Square
 *   payment ID; a tip is kept on the payment apart from the amount applied to the invoice. Card reader payments
 *   (Android app, Mobile Payments SDK) carry our charge's reference_id and complete that charge; FAILED / CANCELED
 *   ones leave the invoice unpaid. A payment by link that pays an invoice notifies "Invoice #N paid".
 * - Square gets no buyer email or phone: the customer receives only our receipt.
 * - refund.created / refund.updated webhooks record completed refunds of those payments (balance and status follow).
 * - oauth.authorization.revoked forgets a connection revoked from the Square dashboard.
 */
class SquareProvider implements PaymentProvider
{
    public function __construct(
        private readonly SquareClient $client,
        private readonly RecordPayment $recordPayment,
        private readonly CurrentCompany $currentCompany,
        private readonly AuditLogger $audit,
    ) {}

    public function key(): string
    {
        return 'square';
    }

    public function label(): string
    {
        return 'Square';
    }

    public function isAvailableFor(Company $company): bool
    {
        return $this->client->isConfigured() && $this->servesCountry((string) $company->country);
    }

    public function servesCountry(string $country): bool
    {
        return in_array($country, (array) config('payments.square.countries', []), true);
    }

    public function isConnected(Company $company): bool
    {
        return $this->connection($company) !== null;
    }

    public function connectionSummary(Company $company): ?array
    {
        $connection = $this->connection($company);

        return $connection === null ? null : [
            'account' => $connection->account_name,
            'location' => $connection->location_name,
            'currency' => $connection->currency,
            'connected_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    public function authorizationUrl(Company $company, string $state, array $extraScopes = []): string
    {
        $scopes = array_values(array_unique([...(array) config('payments.square.scopes'), ...$extraScopes]));

        return $this->client->authorizationUrl($state, $this->redirectUri(), $scopes);
    }

    public function connect(Company $company, array $query, User $user): void
    {
        if (! is_string($query['code'] ?? null) || $query['code'] === '') {
            // The Owner pressed "Deny" on Square, or Square reported an error.
            throw new PaymentProviderException(__('payments.square.errors.denied'));
        }

        $token = $this->client->obtainToken($query['code'], $this->redirectUri());

        try {
            $merchant = $this->client->merchant($token['access_token']);
            $location = filled($merchant['main_location_id'] ?? null)
                ? $this->client->location($token['access_token'], $merchant['main_location_id'])
                : [];
        } catch (RequestException) {
            throw new PaymentProviderException(__('payments.square.errors.api'));
        }

        $this->currentCompany->runAs($company, function () use ($company, $token, $merchant, $location, $user) {
            PaymentProviderConnection::query()->updateOrCreate(['provider' => $this->key()], [
                'account_id' => $token['merchant_id'],
                'account_name' => $merchant['business_name'] ?? null,
                'location_id' => $location['id'] ?? null,
                'location_name' => $location['name'] ?? null,
                'currency' => $location['currency'] ?? ($merchant['currency'] ?? null),
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_at']) ? CarbonImmutable::parse($token['expires_at']) : null,
                'connected_by' => $user->id,
            ]);

            // The first connected provider becomes the company's provider.
            if ($company->payment_provider === null) {
                $company->forceFill(['payment_provider' => $this->key()])->save();
            }

            $this->audit->record('payment_provider.connected', $company, [
                'provider' => $this->key(), 'account' => $merchant['business_name'] ?? $token['merchant_id'],
            ]);
        });
    }

    public function disconnect(Company $company): void
    {
        $this->currentCompany->runAs($company, function () use ($company) {
            $connection = PaymentProviderConnection::query()->where('provider', $this->key())->first();

            if ($connection === null) {
                return;
            }

            try {
                $this->client->revokeToken($connection->access_token);
            } catch (Throwable $e) {
                // Still forget the tokens here; the Owner can also revoke in the Square dashboard.
                Log::warning('Square token revoke failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            }

            $this->forget($company, $connection);
            $this->audit->record('payment_provider.disconnected', $company, ['provider' => $this->key()]);
        });
    }

    public function createPaymentLink(Estimate|Invoice $document, int $amount): PaymentLink
    {
        $connection = $this->connection(currentCompany());

        if ($connection === null || $connection->location_id === null) {
            throw new PaymentProviderException(__('payments.square.errors.not_connected'));
        }

        if ($connection->currency !== null && $connection->currency !== $document->currency) {
            throw new PaymentProviderException(__('payments.square.errors.currency', [
                'square' => $connection->currency, 'invoice' => $document->currency,
            ]));
        }

        $document->loadMissing(['brand']);
        $deposit = $document instanceof Estimate;

        $link = $this->client->createPaymentLink($this->accessToken($connection), array_filter([
            'idempotency_key' => (string) Str::uuid(),
            'quick_pay' => [
                'name' => Str::limit(__($deposit ? 'payments.square.deposit_link_name' : 'payments.square.link_name', [
                    'number' => $document->number, 'brand' => $document->brand?->name ?? currentCompany()->name,
                ]), 255, ''),
                'price_money' => ['amount' => $amount, 'currency' => $document->currency],
                'location_id' => $connection->location_id,
            ],
            'payment_note' => Str::limit(__($deposit ? 'payments.square.deposit_payment_note' : 'payments.square.payment_note', ['number' => $document->number]), 500, ''),
            // No buyer email or phone: Square must not send its own receipt; the customer gets ours (owner's task of
            // November 21).
            // Tips go to the company on top of the invoice (company setting); not on deposits.
            'checkout_options' => currentCompany()->online_tips && ! $deposit ? ['allow_tipping' => true] : null,
        ]));

        if (! is_string($link['url'] ?? null) || ! is_string($link['id'] ?? null)) {
            throw new PaymentProviderException(__('payments.square.errors.api'));
        }

        return new PaymentLink($link['url'], $link['id'], $link['order_id'] ?? null);
    }

    public function refund(Payment $payment, int $amount, string $reason): string
    {
        $connection = $this->connection(currentCompany());

        if ($connection === null) {
            throw new PaymentProviderException(__('payments.square.errors.not_connected'));
        }

        try {
            $refund = $this->client->refundPayment($this->accessToken($connection), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_id' => $payment->provider_payment_id,
                'amount_money' => ['amount' => $amount, 'currency' => $payment->currency],
                'reason' => Str::limit($reason, 192, ''),
            ]);
        } catch (Throwable $e) {
            throw new PaymentProviderException(__('payments.refunds.provider_failed', ['error' => $e->getMessage()]));
        }

        if (! is_string($refund['id'] ?? null) || in_array($refund['status'] ?? null, ['REJECTED', 'FAILED'], true)) {
            throw new PaymentProviderException(__('payments.refunds.provider_failed', ['error' => (string) ($refund['status'] ?? 'unknown')]));
        }

        return $refund['id'];
    }

    public function cancelPaymentLink(Company $company, string $providerReference): void
    {
        $connection = $this->connection($company);

        if ($connection === null) {
            return;
        }

        try {
            $this->client->deletePaymentLink($this->accessToken($connection), $providerReference);
        } catch (Throwable $e) {
            Log::info('Square payment link not deleted', ['link' => $providerReference, 'error' => $e->getMessage()]);
        }
    }

    public function handleWebhook(Request $request): void
    {
        $this->verifySignature($request);

        $merchantId = (string) $request->input('merchant_id');
        $type = (string) $request->input('type');

        // Webhooks come for every merchant that authorized the app; find the company by its Square account.
        $connection = PaymentProviderConnection::withoutCompanyScope()
            ->where('provider', $this->key())
            ->where('account_id', $merchantId)
            ->first();

        if ($connection === null) {
            return;
        }

        $company = Company::query()->find($connection->company_id);

        if ($company === null) {
            return;
        }

        $this->currentCompany->runAs($company, function () use ($company, $connection, $type, $request) {
            match ($type) {
                'payment.created', 'payment.updated' => $this->recordPayment((array) $request->input('data.object.payment')),
                'refund.created', 'refund.updated' => $this->recordRefund((array) $request->input('data.object.refund')),
                'oauth.authorization.revoked' => $this->forget($company, $connection),
                default => null,
            };
        });
    }

    /**
     * A completed Square payment for one of our payment links becomes a payment on the invoice (once).
     *
     * @param  array<string, mixed>  $payment
     */
    private function recordPayment(array $payment): void
    {
        if (in_array($payment['status'] ?? null, ['FAILED', 'CANCELED'], true)) {
            // A declined or cancelled card reader payment: the invoice stays unpaid.
            $charge = $this->readerCharge($payment);
            if ($charge !== null) {
                app(ChargeCheckout::class)->fail($charge, 'Square: '.$payment['status']);
            }

            return;
        }

        if (($payment['status'] ?? null) !== 'COMPLETED' || ! is_string($payment['id'] ?? null)) {
            return;
        }

        if (! is_string($payment['order_id'] ?? null)) {
            $this->recordReaderPayment($payment);

            return;
        }

        $link = InvoicePaymentLink::query()
            ->where('provider', $this->key())
            ->where('provider_order_id', $payment['order_id'])
            ->first();

        if ($link === null) {
            // A card reader payment from the Take payment screen, or a payment taken in Square for something else.
            $this->recordReaderPayment($payment);

            return;
        }

        $card = $payment['card_details']['card'] ?? null;
        $reference = is_array($card) && isset($card['card_brand'], $card['last_4'])
            ? Str::of($card['card_brand'])->replace('_', ' ')->title().' •••• '.$card['last_4']
            : ($payment['receipt_number'] ?? null);

        try {
            $this->recordPayment->fromProvider(
                $link->estimate ?? $link->invoice,
                $this->key(),
                $payment['id'],
                (int) ($payment['amount_money']['amount'] ?? 0),
                CarbonImmutable::parse($payment['updated_at'] ?? $payment['created_at'] ?? 'now'),
                $reference,
                $payment['amount_money']['currency'] ?? null,
                (int) ($payment['tip_money']['amount'] ?? 0),
            );
        } catch (ValidationException $e) {
            // E.g. the invoice was voided meanwhile: keep the money visible in Square, log it here.
            Log::warning('Square payment not recorded', ['payment' => $payment['id'], 'invoice' => $link->invoice_id, 'estimate' => $link->estimate_id, 'errors' => $e->errors()]);

            return;
        }

        $link->update(['status' => InvoicePaymentLink::PAID]);

        if ($link->invoice_id !== null) {
            $this->notifyPaidOnline($payment['id']);
        }

        // The processing fee may only come with a later payment.updated: keep the latest.
        $fee = collect($payment['processing_fee'] ?? [])->sum(fn ($f) => (int) ($f['amount_money']['amount'] ?? 0));
        if ($fee > 0) {
            Payment::query()->where('provider', $this->key())->where('provider_payment_id', $payment['id'])
                ->update(['processing_fee' => $fee]);
        }
    }

    /**
     * A completed Square refund of a payment recorded here becomes a refund row on its invoice (once).
     *
     * @param  array<string, mixed>  $refund
     */
    private function recordRefund(array $refund): void
    {
        if (($refund['status'] ?? null) !== 'COMPLETED' || ! is_string($refund['id'] ?? null) || ! is_string($refund['payment_id'] ?? null)) {
            return;
        }

        $payment = Payment::query()
            ->where('provider', $this->key())
            ->where('provider_payment_id', $refund['payment_id'])
            ->whereNull('refunded_payment_id')
            ->first();

        if ($payment === null) {
            return;
        }

        $this->recordPayment->refundFromProvider(
            $payment,
            $refund['id'],
            (int) ($refund['amount_money']['amount'] ?? 0),
            CarbonImmutable::parse($refund['updated_at'] ?? $refund['created_at'] ?? 'now'),
        );
    }

    /**
     * A completed card reader payment (Square reference_id = our charge) is recorded on its invoice, once.
     *
     * @param  array<string, mixed>  $payment
     */
    private function recordReaderPayment(array $payment): void
    {
        $charge = $this->readerCharge($payment);

        if ($charge === null) {
            return;
        }

        try {
            app(ChargeCheckout::class)->complete($charge, $payment);
        } catch (ValidationException $e) {
            Log::warning('Square reader payment not recorded', ['payment' => $payment['id'] ?? null, 'charge' => $charge->reference, 'errors' => $e->errors()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function readerCharge(array $payment): ?Charge
    {
        $reference = $payment['reference_id'] ?? null;

        return is_string($reference) && Str::isUuid($reference)
            ? Charge::query()->where('reference', $reference)->where('method', PaymentMethod::CardReader->value)->first()
            : null;
    }

    /**
     * "Invoice #N paid — $X" to the Owners (and the person who sent the invoice) when the customer paid by the link.
     * The tap opens the Paid screen with Send receipt; nothing is sent to the customer by itself.
     */
    private function notifyPaidOnline(string $squarePaymentId): void
    {
        $payment = Payment::query()->with('invoice')->where('provider', $this->key())->where('provider_payment_id', $squarePaymentId)->first();
        $invoice = $payment?->invoice;

        if ($payment === null || $invoice === null || $invoice->balance > 0) {
            return;
        }

        $alert = new AppAlert(
            'invoice_paid_online',
            __('charges.push.paid_title', [
                'number' => $invoice->number,
                'amount' => Money::format($payment->amount, $payment->currency, currentCompany()->locale),
            ]),
            __('charges.push.paid_body', ['customer' => $invoice->customer?->display_name ?? '']),
            route('payments.paid', $payment),
            mail: false,
        );

        // Once per payment: a repeated webhook does not notify again.
        if (! Cache::add('invoice-paid-push:'.$payment->id, true, now()->addDays(2))) {
            return;
        }

        Alerts::owners(currentCompany(), $alert);
        $sender = $invoice->created_by !== null ? User::query()->find($invoice->created_by) : null;
        if ($sender !== null && $sender->is_active && ! $sender->hasRole(UserRole::Owner)) {
            Alerts::send([$sender], $alert);
        }
    }

    /**
     * The merchant's locations, for Settings → Payments (card reader payments go to the chosen one).
     *
     * @return list<array{id: string, name: string, currency: string|null}>
     */
    public function locations(Company $company): array
    {
        $connection = $this->connection($company) ?? throw new PaymentProviderException(__('payments.square.errors.not_connected'));

        try {
            $locations = $this->client->locations($this->accessToken($connection));
        } catch (Throwable) {
            throw new PaymentProviderException(__('payments.square.errors.api'));
        }

        return collect($locations)
            ->filter(fn (array $l) => ($l['status'] ?? 'ACTIVE') === 'ACTIVE' && is_string($l['id'] ?? null))
            ->map(fn (array $l) => ['id' => $l['id'], 'name' => (string) ($l['name'] ?? $l['id']), 'currency' => $l['currency'] ?? null])
            ->values()->all();
    }

    public function setLocation(Company $company, string $locationId): void
    {
        $location = collect($this->locations($company))->firstWhere('id', $locationId)
            ?? throw new PaymentProviderException(__('charges.errors.location'));

        $this->currentCompany->runAs($company, function () use ($company, $location) {
            PaymentProviderConnection::query()->where('provider', $this->key())->update([
                'location_id' => $location['id'],
                'location_name' => $location['name'],
                'currency' => $location['currency'],
            ]);
            $this->audit->record('payment_provider.location', $company, ['provider' => $this->key(), 'location' => $location['name']]);
        });
    }

    /**
     * What the Android app's card reader plugin needs to authorize the Square Mobile Payments SDK.
     *
     * @return array{application_id: string, access_token: string, location_id: string, environment: string}
     */
    public function readerSession(Company $company): array
    {
        $connection = $this->connection($company);

        if ($connection === null || $connection->location_id === null) {
            throw new PaymentProviderException(__('payments.square.errors.not_connected'));
        }

        return [
            'application_id' => (string) config('services.square.application_id'),
            'access_token' => $this->accessToken($connection),
            'location_id' => $connection->location_id,
            'environment' => config('services.square.environment') === 'production' ? 'production' : 'sandbox',
        ];
    }

    /**
     * The card reader payment the phone reported, as Square has it: it must be COMPLETED, be ours (reference_id)
     * and be for the amount charged.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentProviderException
     */
    public function readerPayment(Charge $charge, string $paymentId): array
    {
        $connection = $this->connection(currentCompany()) ?? throw new PaymentProviderException(__('payments.square.errors.not_connected'));

        try {
            $payment = $this->client->payment($this->accessToken($connection), $paymentId);
        } catch (Throwable) {
            throw new PaymentProviderException(__('payments.square.errors.api'));
        }

        if (($payment['reference_id'] ?? null) !== $charge->reference
            || (int) ($payment['amount_money']['amount'] ?? 0) !== $charge->amount
            || strtoupper((string) ($payment['amount_money']['currency'] ?? '')) !== $charge->currency) {
            throw new PaymentProviderException(__('charges.errors.mismatch'));
        }

        if (($payment['status'] ?? null) !== 'COMPLETED') {
            throw new PaymentProviderException(__('charges.errors.not_completed'));
        }

        return $payment;
    }

    private function verifySignature(Request $request): void
    {
        $key = (string) config('services.square.webhook_signature_key');
        $url = (string) config('services.square.webhook_url');
        $signature = (string) $request->header('x-square-hmacsha256-signature');

        if ($key === '' || $url === '' || $signature === '') {
            throw new InvalidWebhookSignature;
        }

        $expected = base64_encode(hash_hmac('sha256', $url.$request->getContent(), $key, true));

        if (! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignature;
        }
    }

    /**
     * A valid access token, refreshed first when it is about to expire.
     */
    public function accessToken(PaymentProviderConnection $connection): string
    {
        $days = (int) config('payments.square.refresh_before_days', 7);

        if ($connection->refresh_token !== null
            && $connection->token_expires_at !== null
            && $connection->token_expires_at->isBefore(now()->addDays($days))) {
            $this->refresh($connection);
        }

        return $connection->access_token;
    }

    public function refresh(PaymentProviderConnection $connection): void
    {
        $token = $this->client->refreshToken((string) $connection->refresh_token);

        $connection->forceFill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => isset($token['expires_at']) ? CarbonImmutable::parse($token['expires_at']) : null,
        ])->save();
    }

    public function connection(Company $company): ?PaymentProviderConnection
    {
        return $this->currentCompany->runAs($company, fn () => PaymentProviderConnection::query()
            ->where('provider', $this->key())
            ->first());
    }

    private function forget(Company $company, PaymentProviderConnection $connection): void
    {
        $connection->delete();

        if ($company->payment_provider === $this->key()) {
            $company->forceFill(['payment_provider' => null])->save();
        }

        InvoicePaymentLink::query()
            ->where('provider', $this->key())
            ->where('status', InvoicePaymentLink::ACTIVE)
            ->update(['status' => InvoicePaymentLink::REPLACED]);
    }

    private function redirectUri(): string
    {
        return route('payment-providers.callback', ['provider' => $this->key()]);
    }
}
