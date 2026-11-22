<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Charge;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 22: "Card" in the Android app (Square Mobile Payments SDK). The token endpoint, the check of
 * the phone's Square payment by its ID (COMPLETED and exactly the Total), one payment ID paying once, Square errors
 * leaving the invoice unpaid, reference_id = the invoice number and note = the job, card brand and last 4 digits,
 * "Reconnect Square", and the Android download page with its version.
 */
const CARD_SQUARE = 'https://connect.squareupsandbox.com';

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
    Mail::fake();

    $this->company = Company::factory()->create([
        'name' => 'Doctor Appliance', 'timezone' => 'America/Vancouver', 'country' => 'CA', 'currency' => 'CAD',
        'prices_include_tax' => false, 'business_hours' => Company::defaultBusinessHours(),
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true]);
    $this->connection = inCompany($this->company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'MLR1', 'account_name' => 'Doctor Appliance',
        'location_id' => 'LOC1', 'location_name' => 'Surrey', 'currency' => 'CAD',
        'access_token' => 'EAAAl-card-token', 'refresh_token' => 'EQAAl-refresh', 'token_expires_at' => now()->addDays(30),
    ]));
    $this->company->update(['payment_provider' => 'square']);

    $this->customer = Customer::factory()->for($this->company)->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create();

    $this->actingAs($this->owner);
});

/** Square answers GET /v2/payments/{id} with $payment (or with an error status). */
function cardSquare(?array $payment, int $status = 200, array $scopes = ['PAYMENTS_WRITE', 'PAYMENTS_WRITE_IN_PERSON', 'PAYMENTS_READ', 'MERCHANT_PROFILE_READ']): void
{
    Http::swap(new HttpFactory);
    Http::fake([
        CARD_SQUARE.'/v2/payments/*' => $payment === null ? Http::response(['errors' => [['code' => 'INTERNAL_SERVER_ERROR']]], $status) : Http::response(['payment' => $payment], $status),
        CARD_SQUARE.'/v2/locations' => Http::response(['locations' => [['id' => 'LOC1', 'name' => 'Surrey', 'currency' => 'CAD', 'status' => 'ACTIVE']]]),
        CARD_SQUARE.'/oauth2/token/status' => Http::response(['scopes' => $scopes, 'merchant_id' => 'MLR1']),
    ]);
}

function cardPayment(Charge $charge, string $id = 'PAYC1', string $status = 'COMPLETED', ?int $amount = null): array
{
    return [
        'id' => $id, 'status' => $status, 'location_id' => 'LOC1', 'reference_id' => $charge->square_reference,
        'amount_money' => ['amount' => $amount ?? $charge->amount, 'currency' => 'CAD'],
        'card_details' => ['status' => 'CAPTURED', 'entry_method' => 'EMV', 'card' => ['card_brand' => 'VISA', 'last_4' => '4242']],
        'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
    ];
}

function cardInvoice(string $description = 'Dryer not heating', string $price = '100.00'): Invoice
{
    $job = ServiceJob::factory()->for(test()->property)->create(['brand_id' => test()->brand->id, 'status' => 'completed', 'description' => $description]);
    $taxes = inCompany(test()->company, fn () => TaxRate::query()->pluck('id')->all());
    test()->post(route('invoices.store', $job), documentPayload([
        'tax_rate_ids' => $taxes,
        'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => $price, 'taxable' => true]],
    ]))->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => Invoice::query()->latest('id')->firstOrFail());
}

function cardCharge(Invoice $invoice): Charge
{
    test()->postJson(route('charges.store'), ['method' => 'card_reader', 'invoice_id' => $invoice->id])->assertOk();

    return inCompany(test()->company, fn () => Charge::query()->latest('id')->firstOrFail());
}

function cardWebhook(array $payment)
{
    $body = json_encode([
        'merchant_id' => 'MLR1', 'type' => 'payment.updated', 'event_id' => (string) Str::uuid(),
        'data' => ['type' => 'payment', 'id' => $payment['id'], 'object' => ['payment' => $payment]],
    ]);
    $signature = base64_encode(hash_hmac('sha256', config('services.square.webhook_url').$body, config('services.square.webhook_signature_key'), true));

    return test()->call('POST', route('webhooks.payments', 'square'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
    ], $body);
}

describe('the token endpoint', function () {
    test('only a signed-in person of the company who takes payments gets the Square token and location', function () {
        $stranger = memberOf(Company::factory()->create(), UserRole::Owner);
        $collector = memberOf($this->company, UserRole::Collector);

        $this->actingAs($this->tech)->getJson(route('payments.reader.session'))
            ->assertOk()
            ->assertJsonPath('access_token', 'EAAAl-card-token')
            ->assertJsonPath('location_id', 'LOC1')
            ->assertJsonPath('application_id', 'sandbox-sq0idb-app');

        // Not signed in.
        auth()->logout();
        $this->getJson(route('payments.reader.session'))->assertUnauthorized();

        // Another company (no Square of its own) never gets this company's token.
        $this->actingAs($stranger)->getJson(route('payments.reader.session'))
            ->assertStatus(422)
            ->assertDontSee('EAAAl-card-token');

        // People who do not take payments.
        $this->actingAs($collector)->getJson(route('payments.reader.session'))->assertForbidden();
    });
});

describe('checking the card payment by its ID', function () {
    test('Card sends the invoice number as reference_id and the job as the note; nothing about the customer', function () {
        $invoice = cardInvoice('Dryer not heating, makes noise');

        $this->postJson(route('charges.store'), ['method' => 'card_reader', 'invoice_id' => $invoice->id])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('reference_id', $invoice->number)
            ->assertJsonPath('note', 'Dryer not heating, makes noise')
            ->assertJsonPath('amount', 10500)
            ->assertJsonMissingPath('email')
            ->assertJsonMissingPath('phone');
    });

    test('COMPLETED for exactly the Total: the invoice is paid by Card with the payment ID, brand and last 4', function () {
        $invoice = cardInvoice();
        $charge = cardCharge($invoice);

        cardSquare(cardPayment($charge));
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAYC1'])
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        inCompany($this->company, function () use ($invoice, $charge) {
            $payment = Payment::query()->sole();
            expect($payment->method)->toBe(PaymentMethod::CardReader)
                ->and($payment->amount)->toBe(10500)
                ->and($payment->provider_payment_id)->toBe('PAYC1')
                ->and($payment->card_brand)->toBe('Visa')
                ->and($payment->card_last4)->toBe('4242')
                ->and($payment->reference)->toBe('Visa •••• 4242')
                ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
                ->and($charge->fresh()->provider_payment_id)->toBe('PAYC1');
        });
    });

    test('not COMPLETED, or not exactly the Total: nothing is paid', function (string $status, int $amount) {
        $invoice = cardInvoice();
        $charge = cardCharge($invoice);

        cardSquare(cardPayment($charge, status: $status, amount: $amount));
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAYC1'])->assertStatus(422);

        inCompany($this->company, function () use ($invoice, $charge) {
            expect(Payment::query()->count())->toBe(0)
                ->and($invoice->fresh()->status)->not->toBe(InvoiceStatus::Paid)
                ->and($invoice->fresh()->balance)->toBe(10500)
                ->and($charge->fresh()->status)->toBe(Charge::PENDING);
        });
    })->with([
        'approved, not completed' => ['APPROVED', 10500],
        'pending' => ['PENDING', 10500],
        'failed' => ['FAILED', 10500],
        'less than the Total' => ['COMPLETED', 10499],
        'more than the Total' => ['COMPLETED', 10600],
    ]);

    test('a payment ID already recorded never pays a second invoice', function () {
        $first = cardInvoice();
        $charge = cardCharge($first);
        cardSquare(cardPayment($charge, 'PAYDUP'));
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAYDUP'])->assertOk();

        // The same Square payment reported for another invoice of the same amount (and reference on Square's side).
        $second = cardInvoice();
        $other = cardCharge($second);
        cardSquare([...cardPayment($other, 'PAYDUP')]);
        $this->postJson(route('charges.complete', $other), ['payment_id' => 'PAYDUP'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_id');

        inCompany($this->company, function () use ($first, $second, $other) {
            expect(Payment::query()->where('provider_payment_id', 'PAYDUP')->count())->toBe(1)
                ->and($first->fresh()->status)->toBe(InvoiceStatus::Paid)
                ->and($second->fresh()->balance)->toBe(10500)
                ->and($other->fresh()->status)->not->toBe(Charge::PAID);
        });
    });

    test('the same payment reported twice (and by the webhook) is recorded once', function () {
        $invoice = cardInvoice();
        $charge = cardCharge($invoice);
        cardSquare(cardPayment($charge));

        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAYC1'])->assertOk();
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAYC1'])->assertOk();
        cardWebhook(cardPayment($charge))->assertNoContent();

        // Try again on the phone after a lost answer: a second charge for the same invoice gets the same payment.
        $retry = new Charge(['amount' => $charge->amount, 'currency' => 'CAD', 'method' => PaymentMethod::CardReader, 'invoice_id' => $invoice->id, 'user_id' => $this->owner->id]);
        inCompany($this->company, function () use ($retry, $invoice) {
            $retry->square_reference = $invoice->number;
            $retry->save();
        });
        $this->postJson(route('charges.complete', $retry), ['payment_id' => 'PAYC1'])->assertOk()->assertJsonPath('status', 'paid');

        inCompany($this->company, fn () => expect(Payment::query()->count())->toBe(1));
    });

    test('a Square error leaves the invoice unpaid and the charge waiting', function () {
        $invoice = cardInvoice();
        $charge = cardCharge($invoice);

        cardSquare(null, 500);
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAYC1'])->assertStatus(422);

        inCompany($this->company, function () use ($invoice, $charge) {
            expect(Payment::query()->count())->toBe(0)
                ->and($invoice->fresh()->balance)->toBe(10500)
                ->and($invoice->fresh()->status)->not->toBe(InvoiceStatus::Paid)
                ->and($charge->fresh()->status)->toBe(Charge::PENDING);
        });
    });

    test('the webhook finds the charge by the invoice number', function () {
        $invoice = cardInvoice();
        $charge = cardCharge($invoice);

        cardWebhook(cardPayment($charge, 'PAYWH'))->assertNoContent();

        inCompany($this->company, function () use ($invoice, $charge) {
            expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
                ->and($charge->fresh()->status)->toBe(Charge::PAID)
                ->and(Payment::query()->sole()->card_last4)->toBe('4242');
        });
    });
});

describe('New charge (Charge → Card)', function () {
    test('the invoice number is taken at Card, kept on Try again, and the paid invoice gets it', function () {
        $draft = ['lines' => [['description' => 'Washer drain pump', 'amount' => '105.00']], 'amounts_include_tax' => true, 'customer' => null];

        $first = $this->postJson(route('charges.store'), ['method' => 'card_reader', 'draft' => $draft])
            ->assertOk()
            ->assertJsonPath('note', 'Washer drain pump');
        $number = $first->json('reference_id');
        expect($number)->toBeString()->not->toBe('');

        // The card was declined; Try again keeps the same number.
        $charge = inCompany($this->company, fn () => Charge::query()->latest('id')->firstOrFail());
        $this->postJson(route('charges.fail', $charge), ['error' => 'PAYMENT_DECLINED: Card declined'])->assertOk();
        $again = $this->postJson(route('charges.store'), ['method' => 'card_reader', 'draft' => $draft])->assertOk();
        expect($again->json('reference_id'))->toBe($number);

        $retry = inCompany($this->company, fn () => Charge::query()->latest('id')->firstOrFail());
        cardSquare(cardPayment($retry, 'PAYNEW'));
        $this->postJson(route('charges.complete', $retry), ['payment_id' => 'PAYNEW'])->assertOk();

        inCompany($this->company, function () use ($number, $charge) {
            $invoice = Invoice::query()->sole();
            expect($invoice->number)->toBe($number)
                ->and($invoice->status)->toBe(InvoiceStatus::Paid)
                ->and($charge->fresh()->status)->toBe(Charge::FAILED);
        });
    });
});

describe('Settings → Payments', function () {
    test('"Reconnect Square" when the connection lacks the in-person permission', function () {
        cardSquare(null, scopes: ['PAYMENTS_READ', 'PAYMENTS_WRITE', 'MERCHANT_PROFILE_READ']);
        $this->getJson(route('payment-providers.square.locations'))
            ->assertOk()
            ->assertJsonPath('reconnect', true)
            ->assertJsonPath('reconnect_url', route('payment-providers.connect', 'square'));

        cache()->flush();
        cardSquare(null);
        $this->getJson(route('payment-providers.square.locations'))->assertOk()->assertJsonPath('reconnect', false);
    });
});

describe('the Android download page', function () {
    beforeEach(function () {
        $this->apk = storage_path('framework/testing/android-card/android.apk');
        config(['payments.android_apk_path' => $this->apk]);
        @mkdir(dirname($this->apk), 0777, true);
        @unlink($this->apk);
        @unlink(dirname($this->apk).'/android.json');
    });

    afterEach(function () {
        @unlink($this->apk);
        @unlink(dirname($this->apk).'/android.json');
    });

    test('Owner only: version and Download; nothing yet before the first build', function () {
        $this->get(route('android.show'))->assertInertia(fn (Assert $page) => $page->component('android/index')->where('release', null));

        file_put_contents($this->apk, str_repeat('A', 2048));
        file_put_contents(dirname($this->apk).'/android.json', json_encode(['version_code' => 42, 'version_name' => '1.0.42', 'built_at' => '2026-11-22T18:00:00Z', 'commit' => 'abcdef1234567890']));

        $this->get(route('android.show'))->assertInertia(fn (Assert $page) => $page
            ->component('android/index')
            ->where('release.version_code', 42)
            ->where('release.version_name', '1.0.42')
            ->where('release.size', 2048)
            ->where('release.url', route('download.android', ['v' => 42])));

        $this->actingAs($this->tech)->get(route('android.show'))->assertForbidden();
    });

    test('the app reads the latest version on start (no sign-in needed)', function () {
        auth()->logout();
        $this->getJson(route('download.android.latest'))->assertOk()->assertExactJson(['version_code' => 0]);

        file_put_contents($this->apk, 'APK');
        file_put_contents(dirname($this->apk).'/android.json', json_encode(['version_code' => 7, 'version_name' => '1.0.7']));

        $this->getJson(route('download.android.latest'))
            ->assertOk()
            ->assertJsonPath('version_code', 7)
            ->assertJsonPath('version_name', '1.0.7')
            ->assertJsonPath('url', route('download.android', ['v' => 7]));
    });
});
