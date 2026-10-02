<?php

namespace App\Payments\Square;

use App\Actions\Billing\RecordPayment;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\User;
use App\Payments\InvalidWebhookSignature;
use App\Payments\PaymentLink;
use App\Payments\PaymentProvider;
use App\Payments\PaymentProviderException;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
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
 *   payment ID; a tip is kept on the payment apart from the amount applied to the invoice.
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
        return $this->client->isConfigured()
            && in_array($company->country, (array) config('payments.square.countries', []), true);
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

    public function authorizationUrl(Company $company, string $state): string
    {
        return $this->client->authorizationUrl($state, $this->redirectUri(), (array) config('payments.square.scopes'));
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

    public function createPaymentLink(Invoice $invoice, int $amount): PaymentLink
    {
        $connection = $this->connection(currentCompany());

        if ($connection === null || $connection->location_id === null) {
            throw new PaymentProviderException(__('payments.square.errors.not_connected'));
        }

        if ($connection->currency !== null && $connection->currency !== $invoice->currency) {
            throw new PaymentProviderException(__('payments.square.errors.currency', [
                'square' => $connection->currency, 'invoice' => $invoice->currency,
            ]));
        }

        $invoice->loadMissing(['brand', 'customer.primaryEmail']);
        $email = $invoice->customer?->primaryEmail?->email;

        $link = $this->client->createPaymentLink($this->accessToken($connection), array_filter([
            'idempotency_key' => (string) Str::uuid(),
            'quick_pay' => [
                'name' => Str::limit(__('payments.square.link_name', [
                    'number' => $invoice->number, 'brand' => $invoice->brand?->name ?? currentCompany()->name,
                ]), 255, ''),
                'price_money' => ['amount' => $amount, 'currency' => $invoice->currency],
                'location_id' => $connection->location_id,
            ],
            'payment_note' => Str::limit(__('payments.square.payment_note', ['number' => $invoice->number]), 500, ''),
            'pre_populated_data' => $email ? ['buyer_email' => $email] : null,
            // Tips go to the company on top of the invoice (company setting).
            'checkout_options' => currentCompany()->online_tips ? ['allow_tipping' => true] : null,
        ]));

        if (! is_string($link['url'] ?? null) || ! is_string($link['id'] ?? null)) {
            throw new PaymentProviderException(__('payments.square.errors.api'));
        }

        return new PaymentLink($link['url'], $link['id'], $link['order_id'] ?? null);
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
        if (($payment['status'] ?? null) !== 'COMPLETED' || ! is_string($payment['id'] ?? null) || ! is_string($payment['order_id'] ?? null)) {
            return;
        }

        $link = InvoicePaymentLink::query()
            ->where('provider', $this->key())
            ->where('provider_order_id', $payment['order_id'])
            ->first();

        if ($link === null) {
            // A payment taken in Square for something else (in-store sale etc.).
            return;
        }

        $card = $payment['card_details']['card'] ?? null;
        $reference = is_array($card) && isset($card['card_brand'], $card['last_4'])
            ? Str::of($card['card_brand'])->replace('_', ' ')->title().' •••• '.$card['last_4']
            : ($payment['receipt_number'] ?? null);

        try {
            $this->recordPayment->fromProvider(
                $link->invoice,
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
            Log::warning('Square payment not recorded', ['payment' => $payment['id'], 'invoice' => $link->invoice_id, 'errors' => $e->errors()]);

            return;
        }

        $link->update(['status' => InvoicePaymentLink::PAID]);
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

    private function connection(Company $company): ?PaymentProviderConnection
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
