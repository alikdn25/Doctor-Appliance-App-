<?php

use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Mail\ReceiptMail;
use App\Models\Brand;
use App\Models\CashMovement;
use App\Models\Charge;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\TaxRate;
use App\Notifications\AppAlert;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 21: card reader payments (Square Mobile Payments SDK in the Android app), Cash and
 * E-transfer on the one Take payment screen, New charge without a booking (customer, Completed job, paid invoice),
 * Walk-in, the Paid screen with Send receipt and Request review, the online "Invoice paid" push, and Square never
 * getting the customer's email or phone.
 */
const READER_SQUARE = 'https://connect.squareupsandbox.com';

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
        'services.twilio' => [
            'account_sid' => 'ACmaster', 'auth_token' => 'master-token',
            'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com',
        ],
    ]);
    Mail::fake();

    $this->company = Company::factory()->create([
        'name' => 'Doctor Appliance', 'sms_mode' => 'automatic', 'timezone' => 'America/Vancouver', 'country' => 'CA',
        'currency' => 'CAD', 'prices_include_tax' => false, 'business_hours' => Company::defaultBusinessHours(),
        'diagnostic_fee' => 8900,
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Alex Smith']);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tom Tech']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true]);
    TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 1]);
    $this->profile = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Surrey', 'review_url' => 'https://g.page/r/doctor/review']));
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));
    $this->connection = inCompany($this->company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'MLR1', 'account_name' => 'Doctor Appliance',
        'location_id' => 'LOC1', 'location_name' => 'Surrey', 'currency' => 'CAD',
        'access_token' => 'EAAAl-access-1', 'refresh_token' => 'EQAAl-refresh-1', 'token_expires_at' => now()->addDays(30),
    ]));
    $this->company->update(['payment_provider' => 'square']);

    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '12 Main St', 'city' => 'Surrey']);

    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201),
    ]);

    $this->actingAs($this->owner);
});

function readerSquareFake(array $payment): void
{
    // A fresh fake each time: what Square answers now replaces the earlier answer.
    Http::swap(new HttpFactory);
    Http::fake([
        'https://api.twilio.com/*' => Http::response(['sid' => 'SM'.uniqid(), 'status' => 'queued'], 201),
        READER_SQUARE.'/v2/payments/*' => Http::response(['payment' => $payment]),
        READER_SQUARE.'/v2/locations' => Http::response(['locations' => [
            ['id' => 'LOC1', 'name' => 'Surrey', 'currency' => 'CAD', 'status' => 'ACTIVE'],
            ['id' => 'LOC2', 'name' => 'Burnaby', 'currency' => 'CAD', 'status' => 'ACTIVE'],
            ['id' => 'LOC3', 'name' => 'Closed', 'currency' => 'CAD', 'status' => 'INACTIVE'],
        ]]),
    ]);
}

function readerPayment(Charge $charge, string $id = 'PAY1', string $status = 'COMPLETED', ?int $amount = null): array
{
    return [
        'id' => $id, 'status' => $status, 'location_id' => 'LOC1', 'reference_id' => $charge->reference,
        'amount_money' => ['amount' => $amount ?? $charge->amount, 'currency' => 'CAD'],
        'total_money' => ['amount' => $amount ?? $charge->amount, 'currency' => 'CAD'],
        'card_details' => ['status' => 'CAPTURED', 'entry_method' => 'CONTACTLESS', 'card' => ['card_brand' => 'INTERAC', 'last_4' => '4242']],
        'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
    ];
}

function readerWebhook(array $payment, string $type = 'payment.updated')
{
    $body = json_encode([
        'merchant_id' => 'MLR1', 'type' => $type, 'event_id' => (string) Str::uuid(),
        'data' => ['type' => 'payment', 'id' => $payment['id'], 'object' => ['payment' => $payment]],
    ]);
    $signature = base64_encode(hash_hmac('sha256', config('services.square.webhook_url').$body, config('services.square.webhook_signature_key'), true));

    return test()->call('POST', route('webhooks.payments', 'square'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature,
    ], $body);
}

function chargeDraft(array $overrides = []): array
{
    return [...[
        'lines' => [['description' => 'Dryer repair', 'amount' => '112.00']],
        'amounts_include_tax' => true,
        'customer' => null,
    ], ...$overrides];
}

function readerInvoice(): Invoice
{
    $taxes = inCompany(test()->company, fn () => TaxRate::query()->orderBy('sort_order')->pluck('id')->all());
    test()->post(route('invoices.store', ServiceJob::factory()->for(test()->property)->create(['brand_id' => test()->brand->id, 'status' => 'completed'])), documentPayload([
        'tax_rate_ids' => $taxes,
        'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '150.00', 'taxable' => true]],
    ]))->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => Invoice::latest('id')->first());
}

function lastCharge(): Charge
{
    return inCompany(test()->company, fn () => Charge::query()->latest('id')->firstOrFail());
}

describe('taxes on New charge', function () {
    test('"with tax" takes GST and PST out of the amount; "without tax" adds them on top', function () {
        $this->postJson(route('charges.preview'), ['lines' => [['description' => 'Repair', 'amount' => '112.00']], 'amounts_include_tax' => true])
            ->assertOk()
            ->assertJsonPath('subtotal', '$100.00')
            ->assertJsonPath('taxes.0.name', 'GST')
            ->assertJsonPath('taxes.0.amount', '$5.00')
            ->assertJsonPath('taxes.1.amount', '$7.00')
            ->assertJsonPath('total_minor', 11200);

        $this->postJson(route('charges.preview'), ['lines' => [['description' => 'Repair', 'amount' => '100.00']], 'amounts_include_tax' => false])
            ->assertOk()
            ->assertJsonPath('subtotal', '$100.00')
            ->assertJsonPath('total_minor', 11200);
    });

    test('the screen has the quick amounts, the company taxes and the ways to pay', function () {
        $this->get(route('charges.create'))->assertInertia(fn (Assert $page) => $page
            ->component('charges/new')
            ->where('quick_amounts.0', ['label' => 'Diagnostic', 'amount' => '89.00'])
            ->where('taxes.0.name', 'GST')
            ->where('reader.connected', true)
            ->where('methods.cash', true)
            ->where('job', null));
    });
});

describe('New charge without a booking', function () {
    test('Cash: a new customer by phone, a Completed job with "Me" and a paid invoice', function () {
        $this->actingAs($this->tech);

        $response = $this->postJson(route('charges.store'), [
            'method' => 'cash',
            'draft' => chargeDraft(['customer' => [
                'phone' => '604 555 0199', 'first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com',
                'property' => ['line1' => '5 Oak Ave', 'city' => 'Burnaby'], 'billing_same' => false,
                'billing' => ['line1' => 'PO Box 9', 'city' => 'Vancouver'],
            ]]),
        ])->assertOk()->assertJsonPath('status', 'paid');

        inCompany($this->company, function () use ($response) {
            $payment = Payment::query()->latest('id')->firstOrFail();
            expect($response->json('paid_url'))->toBe(route('payments.paid', $payment));
            expect($payment->method)->toBe(PaymentMethod::Cash)->and($payment->amount)->toBe(11200)->and($payment->user_id)->toBe($this->tech->id);

            $invoice = $payment->invoice;
            expect($invoice->status)->toBe(InvoiceStatus::Paid)
                ->and($invoice->total)->toBe(11200)
                ->and($invoice->subtotal)->toBe(10000)
                ->and(collect($invoice->taxes)->pluck('name')->all())->toBe(['GST', 'PST']);

            $customer = $invoice->customer;
            expect($customer->display_name)->toBe('Ann Lee')
                ->and($customer->is_walk_in)->toBeFalse()
                ->and($customer->phones()->value('number_normalized'))->toBe('+16045550199')
                ->and($customer->primaryEmail->email)->toBe('ann@example.com')
                ->and($customer->primaryProperty->line1)->toBe('5 Oak Ave')
                ->and($customer->billing_line1)->toBe('PO Box 9');

            $job = $invoice->job;
            expect($job->status)->toBeIn([JobStatus::Completed, JobStatus::Paid, JobStatus::Invoiced])
                ->and($job->customer_id)->toBe($customer->id)
                ->and($job->visits()->first()->assignees()->pluck('users.id')->all())->toBe([$this->tech->id]);

            // Cash is on hand with the person who took it.
            expect(CashMovement::query()->where('user_id', $this->tech->id)->exists())->toBeTrue();
            expect(Charge::query()->sole()->status)->toBe(Charge::PAID);
        });
    });

    test('a phone already in the database links to that customer (lookup fills the form)', function () {
        $this->getJson(route('charges.lookup', ['phone' => '(604) 555-0142']))
            ->assertOk()
            ->assertJsonPath('customer.id', $this->customer->id)
            ->assertJsonPath('customer.first_name', 'Jane')
            ->assertJsonPath('customer.property.line1', '12 Main St');

        $this->postJson(route('charges.store'), [
            'method' => 'bank_transfer',
            'draft' => chargeDraft(['customer' => ['phone' => '6045550142', 'first_name' => 'Someone']]),
        ])->assertOk();

        inCompany($this->company, function () {
            $invoice = Invoice::query()->latest('id')->firstOrFail();
            expect($invoice->customer_id)->toBe($this->customer->id)
                ->and($invoice->customer->first_name)->toBe('Jane')
                ->and(Customer::query()->count())->toBe(1)
                ->and($invoice->payments()->first()->method)->toBe(PaymentMethod::BankTransfer);
        });
    });

    test('only an amount: the "Walk-in" customer (one for the company); details on the Paid screen replace it', function () {
        $this->postJson(route('charges.store'), ['method' => 'cash', 'draft' => chargeDraft()])->assertOk();
        $this->postJson(route('charges.store'), ['method' => 'cash', 'draft' => chargeDraft(['lines' => [['description' => '', 'amount' => '50']]])])->assertOk();

        [$first, $second] = inCompany($this->company, fn () => Invoice::query()->orderBy('id')->get()->all());
        expect($first->customer_id)->toBe($second->customer_id);
        $walkIn = inCompany($this->company, fn () => Customer::query()->findOrFail($first->customer_id));
        expect($walkIn->is_walk_in)->toBeTrue()->and($walkIn->display_name)->toBe('Walk-in');

        $payment = inCompany($this->company, fn () => $second->payments()->firstOrFail());
        $this->get(route('payments.paid', $payment))->assertInertia(fn (Assert $page) => $page
            ->component('payments/paid')
            ->where('customer.walk_in', true)
            ->where('customer.phone', ''));

        $this->post(route('payments.send-receipt', $payment), [
            'email' => 'bob@example.com', 'phone' => '604-555-0177', 'first_name' => 'Bob', 'last_name' => 'Ray',
            'property' => ['line1' => '9 Elm St', 'city' => 'Surrey'], 'billing_same' => true, 'review' => false,
        ])->assertSessionHasNoErrors();

        inCompany($this->company, function () use ($first, $second) {
            $second->refresh();
            expect($second->customer->display_name)->toBe('Bob Ray')
                ->and($second->customer->emails()->pluck('email')->all())->toBe(['bob@example.com'])
                ->and($second->customer->is_walk_in)->toBeFalse()
                ->and($second->job->customer_id)->toBe($second->customer_id)
                ->and($second->job->property->line1)->toBe('9 Elm St')
                // The other walk-in sale stays with Walk-in.
                ->and($first->fresh()->customer->is_walk_in)->toBeTrue();
        });
    });

    test('Charge reader waits for Square; the payment the phone reports is checked with Square, once', function () {
        $this->postJson(route('charges.store'), ['method' => 'card_reader', 'draft' => chargeDraft()])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', 11200)
            ->assertJsonPath('currency', 'CAD');
        $charge = lastCharge();
        expect(inCompany($this->company, fn () => Invoice::query()->count()))->toBe(0);

        // A payment for another amount (or not ours) is refused.
        readerSquareFake(readerPayment($charge, amount: 500));
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAY1'])->assertStatus(422);
        expect(inCompany($this->company, fn () => Payment::query()->count()))->toBe(0);

        readerSquareFake(readerPayment($charge));
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAY1'])->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson(route('charges.complete', $charge), ['payment_id' => 'PAY1'])->assertOk();
        // The webhook for the same payment comes too.
        readerWebhook(readerPayment($charge))->assertNoContent();

        inCompany($this->company, function () {
            $payment = Payment::query()->sole();
            expect($payment->method)->toBe(PaymentMethod::CardReader)
                ->and($payment->provider)->toBe('square')
                ->and($payment->provider_payment_id)->toBe('PAY1')
                ->and($payment->reference)->toBe('Interac •••• 4242')
                ->and($payment->invoice->status)->toBe(InvoiceStatus::Paid)
                ->and($payment->invoice->customer->is_walk_in)->toBeTrue();
        });

        Http::assertSent(fn (HttpRequest $r) => $r->url() === READER_SQUARE.'/v2/payments/PAY1' && $r->hasHeader('Authorization', 'Bearer EAAAl-access-1'));
    });

    test('Square gets no customer email or phone for its receipts', function () {
        $this->postJson(route('charges.store'), [
            'method' => 'card_reader',
            'draft' => chargeDraft(['customer' => ['phone' => '6045550142', 'email' => 'jane@example.com']]),
        ])->assertOk()
            ->assertJsonMissingPath('customer')
            ->assertDontSee('jane@example.com')
            ->assertDontSee('5550142');

        readerSquareFake(readerPayment(lastCharge()));
        $this->postJson(route('charges.complete', lastCharge()), ['payment_id' => 'PAY1'])->assertOk();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'squareup') && (str_contains($r->body(), 'jane@example.com') || str_contains($r->body(), '5550142')));
    });

    test('only the person who started a reader charge can complete it; another company never sees it', function () {
        $other = Company::factory()->create();
        $otherOwner = memberOf($other, UserRole::Owner);
        $this->postJson(route('charges.store'), ['method' => 'card_reader', 'draft' => chargeDraft()])->assertOk();
        $charge = lastCharge();

        $this->actingAs($this->tech)->postJson(route('charges.complete', $charge), ['payment_id' => 'PAY1'])->assertNotFound();

        $this->actingAs($otherOwner)->postJson(route('charges.complete', $charge), ['payment_id' => 'PAY1'])->assertNotFound();
    });
});

describe('webhooks for reader payments', function () {
    test('a completed payment marks the invoice Paid; a repeated webhook does not pay twice', function () {
        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'card_reader', 'invoice_id' => $invoice->id])->assertOk()->assertJsonPath('amount', $invoice->balance);
        $charge = lastCharge();

        readerWebhook(readerPayment($charge, 'PAYW'), 'payment.created')->assertNoContent();
        readerWebhook(readerPayment($charge, 'PAYW'))->assertNoContent();

        inCompany($this->company, function () use ($invoice, $charge) {
            expect(Payment::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
                ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
                ->and($charge->fresh()->status)->toBe(Charge::PAID);
        });
    });

    test('a failed or cancelled payment leaves the invoice unpaid', function () {
        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'card_reader', 'invoice_id' => $invoice->id])->assertOk();
        $charge = lastCharge();

        readerWebhook(readerPayment($charge, 'PAYF', 'FAILED'))->assertNoContent();
        readerWebhook(readerPayment($charge, 'PAYC', 'CANCELED'))->assertNoContent();

        inCompany($this->company, function () use ($invoice, $charge) {
            expect(Payment::query()->count())->toBe(0)
                ->and($invoice->fresh()->status)->not->toBe(InvoiceStatus::Paid)
                ->and($charge->fresh()->status)->toBe(Charge::FAILED);
        });

        // The phone can report the failure too; Try again starts a new charge.
        $this->postJson(route('charges.fail', $charge), ['error' => 'CANCELED: buyer cancelled'])->assertOk();
    });

    test('a failed card payment is written to card-payments.log with the SDK error, phone and build', function () {
        $path = storage_path('framework/testing/card-payments-'.Str::random(8).'.log');
        config(['logging.channels.card_payments' => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);

        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'card_reader', 'invoice_id' => $invoice->id])->assertOk();
        $charge = lastCharge();

        $this->postJson(route('charges.fail', $charge), [
            'error' => 'PAYMENT_FAILED: The payment did not complete.',
            'code' => 'PAYMENT_FAILED',
            'message' => 'The payment did not complete.',
            'debug_code' => 'tap_to_pay_unavailable',
            'debug_message' => 'Device not supported',
            'details' => ['category=PAYMENT code=TAP_TO_PAY_UNAVAILABLE'],
            'sdk_version' => '2.7.0',
            'device' => 'samsung SM-S921W',
            'android' => '16 (API 36)',
            'app_build' => 'release',
            'application_id' => 'sandbox-sq0idb-app',
            'environment' => 'sandbox',
        ])->assertOk();

        $log = file_get_contents($path);
        expect($log)->toContain('Card payment failed')
            ->toContain('"error_code":"PAYMENT_FAILED"')
            ->toContain('"debug_code":"tap_to_pay_unavailable"')
            ->toContain('"sdk_version":"2.7.0"')
            ->toContain('"device":"samsung SM-S921W"')
            ->toContain('"android":"16 (API 36)"')
            ->toContain('"amount_minor":'.$charge->amount)
            ->toContain('"application_ids_match":true')
            ->and(inCompany($this->company, fn () => $charge->fresh()->status))->toBe(Charge::FAILED);

        @unlink($path);
    });

    test('a payment of a sale not made here is ignored', function () {
        readerWebhook(['id' => 'X1', 'status' => 'COMPLETED', 'reference_id' => (string) Str::uuid(), 'amount_money' => ['amount' => 100, 'currency' => 'CAD']])->assertNoContent();

        expect(inCompany($this->company, fn () => Payment::query()->count()))->toBe(0);
    });
});

describe('Take payment for an invoice or a job', function () {
    test('Cash and E-transfer on an invoice: partial amount allowed, more than the balance refused', function () {
        $invoice = readerInvoice();

        $this->get(route('invoices.take-payment', $invoice))->assertInertia(fn (Assert $page) => $page
            ->component('payments/take')
            ->where('invoice.balance', $invoice->balance)
            ->where('invoice.balance_input', '168.00'));

        $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id, 'amount' => '500'])->assertStatus(422);
        $this->postJson(route('charges.store'), ['method' => 'bank_transfer', 'invoice_id' => $invoice->id, 'amount' => '68'])->assertOk();
        expect($invoice->fresh()->balance)->toBe(10000);
        $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id])->assertOk();

        inCompany($this->company, function () use ($invoice) {
            expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
                ->and($invoice->payments()->pluck('method')->map->value->all())->toBe(['bank_transfer', 'cash']);
        });

        // Paid already: Take payment opens the Paid screen.
        $this->get(route('invoices.take-payment', $invoice))->assertRedirect();
    });

    test('a job without an invoice: quick invoice first (taxes by code), then Take payment', function () {
        $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'in_progress']);

        $this->get(route('jobs.take-payment', $job))->assertInertia(fn (Assert $page) => $page
            ->component('charges/new')
            ->where('job.id', $job->id));

        $this->post(route('jobs.quick-invoice', $job), [
            'lines' => [['description' => 'Diagnostic', 'amount' => '89'], ['description' => 'Belt', 'amount' => '40']],
            'amounts_include_tax' => false,
        ])->assertRedirect();

        $invoice = inCompany($this->company, fn () => Invoice::query()->where('service_job_id', $job->id)->sole());
        expect($invoice->subtotal)->toBe(12900)->and($invoice->total)->toBe(14448);

        $this->get(route('jobs.take-payment', $job))->assertRedirect(route('invoices.take-payment', $invoice));
    });
});

describe('Paid screen and the receipt', function () {
    test('the receipt goes by email only; with "Request review" the review block is in the same email', function () {
        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id])->assertOk();
        $payment = inCompany($this->company, fn () => Payment::query()->sole());

        $this->get(route('payments.paid', $payment))->assertInertia(fn (Assert $page) => $page
            ->where('payment.method', 'Cash')
            ->where('invoice.number', $invoice->number)
            ->where('invoice.paid', true)
            ->where('customer.email', '')
            ->missing('sms')
            ->where('default_profile_id', $this->profile->id));

        // Nothing goes before Send.
        Mail::assertNothingQueued();

        $this->post(route('payments.send-receipt', $payment), [
            'email' => 'Jane@Example.com', 'review' => true, 'google_profile_id' => $this->profile->id,
            // An old client still sending "sms": ignored, never texted.
            'channels' => ['sms'], 'phone' => '+16045550142',
        ])->assertSessionHasNoErrors();

        Mail::assertQueued(ReceiptMail::class, 1);
        Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->hasTo('jane@example.com') && $mail->reviewProfileId === $this->profile->id);
        expect(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'twilio')))->toHaveCount(0)
            ->and(inCompany($this->company, fn () => ReviewRequest::query()->where('service_job_id', $invoice->service_job_id)->value('channel')))->toBe('email')
            // The email typed is kept on the customer.
            ->and(inCompany($this->company, fn () => $this->customer->emails()->pluck('email')->all()))->toBe(['jane@example.com']);

        Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $mail) => str_contains((string) $mail->reviewText, '30 seconds</b> to help me grow'));

        $html = (new ReceiptMail($invoice, $this->profile->id, '<b>Hey Jane</b>, review us: https://g.page/r/doctor/review'))->render();
        expect($html)->toContain('<b>Hey Jane</b>, review us:')->not->toContain(__('documents.mail.review.title'))
            ->toContain('https://g.page/r/doctor/review')->toContain('Paid')->toContain('$168.00')->toContain('Cash')
            ->toContain('GST')->toContain('PST')->toContain($invoice->number);
    });

    test('the Paid screen shows the ready review text; the text changed there goes into the receipt', function () {
        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id])->assertOk();
        $payment = inCompany($this->company, fn () => Payment::query()->sole());

        $this->get(route('payments.paid', $payment))->assertInertia(fn (Assert $page) => $page
            ->where('profiles.0.text', fn (string $text) => str_contains($text, '30 seconds</b> to help me grow my small business by leaving a Google review? https://g.page/r/doctor/review')));

        $this->post(route('payments.send-receipt', $payment), [
            'email' => 'jane@example.com', 'review' => true, 'google_profile_id' => $this->profile->id,
            'review_text' => '<b>Thanks Jane!</b> A review would help a lot.',
        ])->assertSessionHasNoErrors();

        Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->reviewText === "<b>Thanks Jane!</b> A review would help a lot.\nhttps://g.page/r/doctor/review");
    });

    test('without the tick the receipt email has no review block', function () {
        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id])->assertOk();
        $payment = inCompany($this->company, fn () => Payment::query()->sole());

        $this->post(route('payments.send-receipt', $payment), ['email' => 'jane@example.com'])->assertSessionHasNoErrors();

        Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->hasTo('jane@example.com') && $mail->reviewProfileId === null);
        expect(inCompany($this->company, fn () => ReviewRequest::query()->count()))->toBe(0)
            ->and((new ReceiptMail($invoice))->render())->not->toContain('https://g.page/r/doctor/review');
    });

    test('the email comes from the customer; Send needs a valid email and, with the tick, a location', function () {
        inCompany($this->company, fn () => $this->customer->emails()->create(['email' => 'jane@example.com', 'is_primary' => true]));
        $invoice = readerInvoice();
        $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id])->assertOk();
        $payment = inCompany($this->company, fn () => Payment::query()->sole());

        $this->get(route('payments.paid', $payment))->assertInertia(fn (Assert $page) => $page->where('customer.email', 'jane@example.com'));

        $this->post(route('payments.send-receipt', $payment), [])->assertSessionHasErrors('email');
        $this->post(route('payments.send-receipt', $payment), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post(route('payments.send-receipt', $payment), ['email' => 'a@b.co', 'review' => true])->assertSessionHasErrors('google_profile_id');
        Mail::assertNothingQueued();
    });
});

describe('online payment of a sent invoice', function () {
    test('"Invoice #N paid" push opens the Paid screen; nothing is sent to the customer by itself', function () {
        Notification::fake();
        $invoice = readerInvoice();
        inCompany($this->company, function () use ($invoice) {
            $link = new InvoicePaymentLink(['provider' => 'square', 'provider_link_id' => 'PL1', 'provider_order_id' => 'ORDER1', 'url' => 'https://square.link/u/A', 'amount' => $invoice->balance, 'currency' => 'CAD', 'status' => 'active']);
            $link->invoice_id = $invoice->id;
            $link->save();
        });
        $payment = ['id' => 'PAYO', 'status' => 'COMPLETED', 'order_id' => 'ORDER1', 'amount_money' => ['amount' => $invoice->balance, 'currency' => 'CAD'],
            'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()];

        readerWebhook($payment)->assertNoContent();
        readerWebhook($payment)->assertNoContent();

        $recorded = inCompany($this->company, fn () => Payment::query()->sole());
        Notification::assertSentToTimes($this->owner, AppAlert::class, 1);
        Notification::assertSentTo($this->owner, AppAlert::class, fn (AppAlert $alert) => $alert->kind === 'invoice_paid_online'
            && $alert->title === "Invoice {$invoice->number} paid — \$168.00"
            && $alert->url === route('payments.paid', $recorded));

        expect(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'twilio')))->toHaveCount(0);
        Mail::assertNothingQueued();

        $this->get(route('payments.paid', $recorded))->assertInertia(fn (Assert $page) => $page
            ->component('payments/paid')
            ->where('payment.method', 'Online (Square)')
            ->has('profiles', 1));
    });
});

describe('settings and the app', function () {
    test('the Owner picks the Square location card reader payments go to', function () {
        readerSquareFake([]);

        $this->getJson(route('payment-providers.square.locations'))->assertOk()->assertJsonCount(2, 'locations');
        $this->put(route('payment-providers.square.location'), ['location_id' => 'LOC2'])->assertRedirect();
        expect($this->connection->fresh())->location_id->toBe('LOC2')->location_name->toBe('Burnaby');

        $this->put(route('payment-providers.square.location'), ['location_id' => 'LOC3'])->assertRedirect();
        expect($this->connection->fresh()->location_id)->toBe('LOC2');

        $this->actingAs($this->tech)->getJson(route('payment-providers.square.locations'))->assertForbidden();
    });

    test('the app gets the Square session for the reader; people who do not take payments do not', function () {
        $collector = memberOf($this->company, UserRole::Collector);
        $this->actingAs($this->tech)->getJson(route('payments.reader.session'))
            ->assertOk()
            ->assertExactJson(['application_id' => 'sandbox-sq0idb-app', 'access_token' => 'EAAAl-access-1', 'location_id' => 'LOC1', 'environment' => 'sandbox']);

        $this->actingAs($collector)->getJson(route('payments.reader.session'))->assertForbidden();
        $this->actingAs($collector)->get(route('charges.create'))->assertForbidden();
    });

    test('Square asks for in-person payments; the APK is served when it is on the server', function () {
        expect(config('payments.square.scopes'))->toContain('PAYMENTS_WRITE_IN_PERSON');

        config(['payments.android_apk_path' => storage_path('framework/testing/android.apk')]);
        @unlink(config('payments.android_apk_path'));
        $this->get('/download/android.apk')->assertNotFound();

        @mkdir(dirname(config('payments.android_apk_path')), 0777, true);
        file_put_contents(config('payments.android_apk_path'), 'APK');
        $this->get('/download/android.apk')->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        unlink(config('payments.android_apk_path'));
    });
});
