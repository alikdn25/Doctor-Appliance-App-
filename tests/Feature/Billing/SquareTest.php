<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Square (SPEC §7.6) against a faked Square API. Request and webhook shapes follow the Square API
 * (OAuth, Merchants, Locations, Checkout payment links, payment.* webhooks with HMAC-SHA256 signatures).
 */
const SQUARE = 'https://connect.squareupsandbox.com';

beforeEach(function () {
    config([
        'services.square' => [
            'environment' => 'sandbox',
            'application_id' => 'sandbox-sq0idb-app',
            'application_secret' => 'sandbox-sq0csb-secret',
            'webhook_signature_key' => 'whsec-test-key',
            'webhook_url' => 'https://app.example.test/webhooks/payments/square',
            'api_version' => '2025-10-16',
        ],
    ]);

    $this->company = Company::factory()->inUnitedStates()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Lone Star']);
    $customer = Customer::factory()->for($this->company)->withEmail('maria@example.com')->create();
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $this->brand->id, 'status' => 'completed']);

    $this->actingAs($this->owner);
});

function fakeSquare(array $extra = []): void
{
    Http::fake([
        SQUARE.'/oauth2/token' => Http::response([
            'access_token' => 'EAAAl-access-1', 'token_type' => 'bearer', 'expires_at' => now()->addDays(30)->toIso8601String(),
            'merchant_id' => 'MLR1', 'refresh_token' => 'EQAAl-refresh-1',
        ]),
        SQUARE.'/oauth2/revoke' => Http::response(['success' => true]),
        SQUARE.'/v2/merchants/me' => Http::response(['merchant' => [
            'id' => 'MLR1', 'business_name' => 'Lone Star Appliance', 'country' => 'US', 'currency' => 'USD', 'main_location_id' => 'LOC1',
        ]]),
        SQUARE.'/v2/locations/LOC1' => Http::response(['location' => [
            'id' => 'LOC1', 'name' => 'Austin', 'currency' => 'USD', 'status' => 'ACTIVE', 'capabilities' => ['CREDIT_CARD_PROCESSING'],
        ]]),
        SQUARE.'/v2/online-checkout/payment-links/*' => Http::response(['deleted_at' => now()->toIso8601String()]),
        SQUARE.'/v2/online-checkout/payment-links' => Http::sequence()
            ->push(['payment_link' => ['id' => 'PL1', 'version' => 1, 'url' => 'https://sandbox.square.link/u/AAA', 'order_id' => 'ORDER1']])
            ->push(['payment_link' => ['id' => 'PL2', 'version' => 1, 'url' => 'https://sandbox.square.link/u/BBB', 'order_id' => 'ORDER2']]),
        ...$extra,
    ]);
}

function connectSquare(Company $company, array $attributes = []): PaymentProviderConnection
{
    $company->update(['payment_provider' => 'square']);

    return inCompany($company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'MLR1', 'account_name' => 'Lone Star Appliance',
        'location_id' => 'LOC1', 'location_name' => 'Austin', 'currency' => 'USD',
        'access_token' => 'EAAAl-access-1', 'refresh_token' => 'EQAAl-refresh-1', 'token_expires_at' => now()->addDays(30),
        ...$attributes,
    ]));
}

function squareInvoice(Company $company, ServiceJob $job): Invoice
{
    test()->post(route('invoices.store', $job), documentPayload())->assertSessionHasNoErrors();

    return inCompany($company, fn () => Invoice::latest('id')->first());
}

/**
 * Sends a Square webhook signed like Square does: base64(HMAC-SHA256(notification URL + body)).
 */
function squareWebhook(array $event, ?string $signature = null)
{
    $body = json_encode($event);
    $signature ??= base64_encode(hash_hmac('sha256', config('services.square.webhook_url').$body, config('services.square.webhook_signature_key'), true));

    return test()->call('POST', route('webhooks.payments', 'square'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
    ], $body);
}

function squarePayment(string $id, int $amount, string $orderId = 'ORDER1', string $status = 'COMPLETED', string $merchant = 'MLR1'): array
{
    return [
        'merchant_id' => $merchant,
        'type' => 'payment.updated',
        'event_id' => (string) Str::uuid(),
        'created_at' => now()->toIso8601String(),
        'data' => ['type' => 'payment', 'id' => $id, 'object' => ['payment' => [
            'id' => $id, 'status' => $status, 'order_id' => $orderId, 'location_id' => 'LOC1',
            'amount_money' => ['amount' => $amount, 'currency' => 'USD'],
            'total_money' => ['amount' => $amount, 'currency' => 'USD'],
            'card_details' => ['status' => 'CAPTURED', 'card' => ['card_brand' => 'VISA', 'last_4' => '1111']],
            'receipt_number' => 'R123', 'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
        ]]],
    ];
}

describe('connecting', function () {
    test('Square is offered only where Square works and only when the app is configured', function () {
        $this->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('providerConnections.0.key', 'square')
            ->where('providerConnections.0.connected', null)
            ->where('paymentProviders', []));

        // Brazil: no Square; Stripe will be the provider there.
        $this->company->update(['country' => 'BR']);
        $this->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page->where('providerConnections', []));
        $this->get(route('payment-providers.connect', 'square'))->assertNotFound();

        $this->company->update(['country' => 'CA']);
        config(['services.square.application_id' => null]);
        $this->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page->where('providerConnections', []));
    });

    test('the Owner connects the company\'s own Square account with OAuth', function () {
        fakeSquare();

        $redirect = $this->get(route('payment-providers.connect', 'square'))->assertRedirect()->headers->get('Location');
        $state = session('payment_provider_oauth.state');

        expect($redirect)->toStartWith(SQUARE.'/oauth2/authorize?')
            ->toContain('client_id=sandbox-sq0idb-app')
            ->toContain('state='.$state)
            ->toContain('PAYMENTS_WRITE')
            ->toContain(rawurlencode(route('payment-providers.callback', 'square')));

        $this->get(route('payment-providers.callback', ['provider' => 'square', 'code' => 'sq0cgb-code', 'state' => $state]))
            ->assertRedirect(route('company.settings.edit'));

        Http::assertSent(fn (Request $r) => $r->url() === SQUARE.'/oauth2/token'
            && $r['code'] === 'sq0cgb-code' && $r['grant_type'] === 'authorization_code'
            && $r['client_secret'] === 'sandbox-sq0csb-secret');

        $connection = inCompany($this->company, fn () => PaymentProviderConnection::sole());
        expect($connection)
            ->account_id->toBe('MLR1')
            ->location_id->toBe('LOC1')
            ->currency->toBe('USD')
            ->access_token->toBe('EAAAl-access-1')
            ->refresh_token->toBe('EQAAl-refresh-1')
            ->and($this->company->fresh()->payment_provider)->toBe('square')
            ->and(AuditLog::where('action', 'payment_provider.connected')->exists())->toBeTrue();

        // Tokens are encrypted at rest.
        $raw = DB::table('payment_provider_connections')->first();
        expect($raw->access_token)->not->toContain('EAAAl-access-1')
            ->and($raw->refresh_token)->not->toContain('EQAAl-refresh-1');

        // And never sent to the browser.
        $this->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('providerConnections.0.connected.account', 'Lone Star Appliance')
            ->where('providerConnections.0.connected.location', 'Austin')
            ->where('paymentProviders', [['value' => 'square', 'label' => 'Square']]))
            ->assertDontSee('EAAAl-access-1');
    });

    test('a callback with a wrong state or a denied authorization connects nothing', function () {
        fakeSquare();
        $this->get(route('payment-providers.connect', 'square'));

        $this->get(route('payment-providers.callback', ['provider' => 'square', 'code' => 'x', 'state' => 'forged']))
            ->assertRedirect(route('company.settings.edit'));

        $this->get(route('payment-providers.connect', 'square'));
        $this->get(route('payment-providers.callback', ['provider' => 'square', 'error' => 'access_denied', 'state' => session('payment_provider_oauth.state')]));

        Http::assertNotSent(fn (Request $r) => $r->url() === SQUARE.'/oauth2/token');
        expect(PaymentProviderConnection::withoutCompanyScope()->count())->toBe(0);
    });

    test('only the Owner connects and disconnects', function () {
        foreach ([UserRole::Admin, UserRole::Technician] as $role) {
            $this->actingAs(memberOf($this->company, $role, ['two_factor_confirmed_at' => now()]));
            $this->get(route('payment-providers.connect', 'square'))->assertForbidden();
            $this->delete(route('payment-providers.disconnect', 'square'))->assertForbidden();
        }
    });

    test('disconnecting revokes the authorization and forgets the tokens', function () {
        fakeSquare();
        connectSquare($this->company);

        $this->delete(route('payment-providers.disconnect', 'square'))->assertRedirect(route('company.settings.edit'));

        Http::assertSent(fn (Request $r) => $r->url() === SQUARE.'/oauth2/revoke'
            && $r->hasHeader('Authorization', 'Client sandbox-sq0csb-secret')
            && $r['access_token'] === 'EAAAl-access-1');
        expect(PaymentProviderConnection::withoutCompanyScope()->count())->toBe(0)
            ->and($this->company->fresh()->payment_provider)->toBeNull();
    });

    test('a connection revoked in the Square dashboard is forgotten', function () {
        connectSquare($this->company);

        squareWebhook(['merchant_id' => 'MLR1', 'type' => 'oauth.authorization.revoked', 'data' => ['type' => 'revocation']])->assertNoContent();

        expect(PaymentProviderConnection::withoutCompanyScope()->count())->toBe(0)
            ->and($this->company->fresh()->payment_provider)->toBeNull();
    });
});

describe('paying an invoice', function () {
    beforeEach(function () {
        fakeSquare();
        connectSquare($this->company);
        $this->invoice = squareInvoice($this->company, $this->job);
    });

    test('a payment link is made for the invoice balance', function () {
        $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page
            ->where('online', ['provider' => 'Square', 'link' => null]));

        $this->post(route('invoices.payment-link', $this->invoice))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->url() === SQUARE.'/v2/online-checkout/payment-links'
            && $r->hasHeader('Authorization', 'Bearer EAAAl-access-1')
            && $r->hasHeader('Square-Version', '2025-10-16')
            && $r['quick_pay']['price_money'] === ['amount' => 28050, 'currency' => 'USD']
            && $r['quick_pay']['location_id'] === 'LOC1'
            && str_contains($r['quick_pay']['name'], $this->invoice->number)
            && $r['pre_populated_data']['buyer_email'] === 'maria@example.com'
            && filled($r['idempotency_key']));

        $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page
            ->where('online.link', ['url' => 'https://sandbox.square.link/u/AAA', 'amount' => 28050, 'currency' => 'USD']));

        // Asking again for the same balance reuses the link.
        $this->post(route('invoices.payment-link', $this->invoice));
        Http::assertSentCount(1);
    });

    test('after a manual partial payment a new link is made for the rest', function () {
        $this->post(route('invoices.payment-link', $this->invoice));
        $this->post(route('payments.store', $this->invoice), ['amount' => '50.00', 'method' => 'cash'])->assertSessionHasNoErrors();

        $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page->where('online.link', null));
        $this->post(route('invoices.payment-link', $this->invoice))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->url() === SQUARE.'/v2/online-checkout/payment-links'
            && $r['quick_pay']['price_money']['amount'] === 23050);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === SQUARE.'/v2/online-checkout/payment-links/PL1');

        $links = inCompany($this->company, fn () => InvoicePaymentLink::orderBy('id')->get());
        expect($links->pluck('status')->all())->toBe(['replaced', 'active'])
            ->and($links[1]->amount)->toBe(23050);
    });

    test('the Square webhook records the payment once, next to manual payments', function () {
        $this->post(route('payments.store', $this->invoice), ['amount' => '50.00', 'method' => 'check'])->assertSessionHasNoErrors();
        $this->post(route('invoices.payment-link', $this->invoice));

        $event = squarePayment('PAY1', 23050);
        squareWebhook($event)->assertNoContent();
        // Square delivers at least once: the same event (or a later payment.updated) again.
        squareWebhook($event)->assertNoContent();
        squareWebhook([...$event, 'type' => 'payment.created'])->assertNoContent();

        $online = inCompany($this->company, fn () => Payment::where('provider', 'square')->get());
        expect($online)->toHaveCount(1)
            ->and($online[0])
            ->method->toBe(PaymentMethod::Online)
            ->provider_payment_id->toBe('PAY1')
            ->amount->toBe(23050)
            ->currency->toBe('USD')
            ->reference->toBe('Visa •••• 1111')
            ->and($this->invoice->fresh())->status->toBe(InvoiceStatus::Paid)->balance->toBe(0)
            ->and(inCompany($this->company, fn () => InvoicePaymentLink::sole())->status)->toBe('paid');

        $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page->where('online', null));
    });

    test('a partial online payment leaves the rest due', function () {
        $this->post(route('invoices.payment-link', $this->invoice));

        squareWebhook(squarePayment('PAY1', 10000))->assertNoContent();

        expect($this->invoice->fresh())->status->toBe(InvoiceStatus::PartiallyPaid)->balance->toBe(18050);
    });

    test('payments that are not completed, not ours, or of another merchant are ignored', function () {
        $this->post(route('invoices.payment-link', $this->invoice));

        squareWebhook(squarePayment('PAY1', 28050, status: 'APPROVED'))->assertNoContent();
        squareWebhook(squarePayment('PAY2', 28050, orderId: 'IN-STORE-SALE'))->assertNoContent();
        squareWebhook(squarePayment('PAY3', 28050, merchant: 'SOMEONE-ELSE'))->assertNoContent();

        expect(Payment::withoutCompanyScope()->where('provider', 'square')->count())->toBe(0);
    });

    test('a webhook with a wrong signature is rejected', function () {
        $this->post(route('invoices.payment-link', $this->invoice));

        squareWebhook(squarePayment('PAY1', 28050), 'forged-signature')->assertUnauthorized();

        expect(Payment::withoutCompanyScope()->count())->toBe(0);
    });

    test('a Square account in another currency cannot make links for the invoice', function () {
        inCompany($this->company, fn () => PaymentProviderConnection::sole()->update(['currency' => 'CAD']));

        $this->post(route('invoices.payment-link', $this->invoice))->assertSessionHasErrors('payment_link');
        Http::assertNothingSent();
    });

    test('a technician on the job can show the QR code on site', function () {
        $tech = memberOf($this->company, UserRole::Technician);
        JobVisit::factory()->for($this->job, 'job')->assignedTo([$tech])->create();

        $this->actingAs($tech)->post(route('invoices.payment-link', $this->invoice))->assertSessionHasNoErrors();
    });

    test('an access token close to expiry is refreshed before use', function () {
        inCompany($this->company, fn () => PaymentProviderConnection::sole()->update(['token_expires_at' => now()->addDay()]));

        $this->post(route('invoices.payment-link', $this->invoice))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->url() === SQUARE.'/oauth2/token'
            && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'EQAAl-refresh-1');
        expect(inCompany($this->company, fn () => PaymentProviderConnection::sole()->token_expires_at->isAfter(now()->addDays(20))))->toBeTrue();
    });
});

test('the daily command refreshes tokens that expire soon', function () {
    fakeSquare();
    connectSquare($this->company, ['token_expires_at' => now()->addDays(2)]);
    $fresh = Company::factory()->create();
    connectSquare($fresh, ['account_id' => 'MLR2', 'token_expires_at' => now()->addDays(25)]);

    $this->artisan('payments:refresh-square-tokens')->assertSuccessful();

    Http::assertSentCount(1);
    expect(inCompany($this->company, fn () => PaymentProviderConnection::sole()->token_expires_at->isAfter(now()->addDays(20))))->toBeTrue();
});

describe('tips and refunds', function () {
    beforeEach(function () {
        fakeSquare();
        connectSquare($this->company);
        $this->invoice = squareInvoice($this->company, $this->job);
        $this->post(route('invoices.payment-link', $this->invoice));
    });

    test('a tip is kept on the payment and not applied to the invoice', function () {
        $event = squarePayment('PAY1', 28050);
        $event['data']['object']['payment']['tip_money'] = ['amount' => 4000, 'currency' => 'USD'];
        $event['data']['object']['payment']['total_money'] = ['amount' => 32050, 'currency' => 'USD'];

        squareWebhook($event)->assertNoContent();

        $payment = inCompany($this->company, fn () => Payment::sole());
        expect($payment)->amount->toBe(28050)->tip_amount->toBe(4000)
            ->and($this->invoice->fresh())->amount_paid->toBe(28050)->balance->toBe(0)->status->toBe(InvoiceStatus::Paid);
    });

    test('tips are offered in the Square checkout when the company allows them', function () {
        $this->company->update(['online_tips' => true]);
        $this->post(route('payments.store', $this->invoice), ['amount' => '1.00', 'method' => 'cash']);
        $this->post(route('invoices.payment-link', $this->invoice));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment-links')
            && ($r['checkout_options']['allow_tipping'] ?? false) === true);
    });

    test('a Square refund puts the amount back on the invoice, once', function () {
        squareWebhook(squarePayment('PAY1', 28050))->assertNoContent();

        $refund = fn (string $id, int $amount, string $status = 'COMPLETED') => [
            'merchant_id' => 'MLR1', 'type' => 'refund.updated', 'event_id' => (string) Str::uuid(),
            'data' => ['type' => 'refund', 'id' => $id, 'object' => ['refund' => [
                'id' => $id, 'status' => $status, 'payment_id' => 'PAY1', 'order_id' => 'ORDER1', 'location_id' => 'LOC1',
                'amount_money' => ['amount' => $amount, 'currency' => 'USD'],
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]]],
        ];

        squareWebhook($refund('REF1', 10000, 'PENDING'))->assertNoContent();
        expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

        squareWebhook($refund('REF1', 10000))->assertNoContent();
        squareWebhook($refund('REF1', 10000))->assertNoContent();

        expect($this->invoice->fresh())->amount_paid->toBe(18050)->balance->toBe(10000)->status->toBe(InvoiceStatus::PartiallyPaid)
            ->and(inCompany($this->company, fn () => Payment::where('provider_payment_id', 'REF1')->sole()))
            ->amount->toBe(-10000)->refunded_payment_id->not->toBeNull();

        // The rest refunded: nothing is paid any more.
        squareWebhook($refund('REF2', 18050))->assertNoContent();
        expect($this->invoice->fresh())->amount_paid->toBe(0)->status->toBe(InvoiceStatus::Refunded)
            ->and(ServiceJob::withoutCompanyScope()->find($this->job->id)->status->value)->not->toBe('paid');

        // A fully refunded invoice can be voided.
        $this->post(route('invoices.void', $this->invoice), ['reason' => 'Job cancelled'])->assertSessionHasNoErrors();
        expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::Void);
    });

    test('a refund larger than the payment amount refunds the tip too', function () {
        $event = squarePayment('PAY1', 28050);
        $event['data']['object']['payment']['tip_money'] = ['amount' => 4000, 'currency' => 'USD'];
        squareWebhook($event);

        squareWebhook(['merchant_id' => 'MLR1', 'type' => 'refund.created', 'data' => ['object' => ['refund' => [
            'id' => 'REF1', 'status' => 'COMPLETED', 'payment_id' => 'PAY1', 'amount_money' => ['amount' => 32050, 'currency' => 'USD'],
        ]]]])->assertNoContent();

        expect(inCompany($this->company, fn () => Payment::where('provider_payment_id', 'REF1')->sole()))
            ->amount->toBe(-28050)->tip_amount->toBe(-4000);
    });
});
