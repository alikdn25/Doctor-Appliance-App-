<?php

use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Jobs\SendOfficeSms;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Notifications\EstimateDecided;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\PublicDocument;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Online approval of estimates on the customer's page (SPEC §7.5): signature, optional lines, expiry, deposit,
 * office notification, conversion, PDF.
 */
const SQUARE_SANDBOX = 'https://connect.squareupsandbox.com';

/** A small valid PNG, as the signature pad sends it. */
function signaturePng(): string
{
    $image = imagecreatetruecolor(40, 20);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 2, 10, 38, 12, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Estimate with a required line ($100 + $20 non-taxable) and an optional one ($50), 10% tax.
 *
 * @param  array<string, mixed>  $overrides
 */
function approvalEstimate(Company $company, ServiceJob $job, array $overrides = []): Estimate
{
    test()->post(route('estimates.store', $job), documentPayload([
        'items' => [
            ['description' => 'Replace drain pump', 'quantity' => '1', 'unit_price' => '100.00', 'taxable' => true],
            ['description' => 'Disposal fee', 'quantity' => '1', 'unit_price' => '20.00', 'taxable' => false],
            ['description' => 'Clean the filter', 'quantity' => '1', 'unit_price' => '50.00', 'taxable' => true, 'optional' => true],
        ],
        ...$overrides,
    ]))->assertSessionHasNoErrors();

    return inCompany($company, fn () => Estimate::query()->latest('id')->first());
}

function estimateToken(Company $company, Estimate $estimate): string
{
    return inCompany($company, fn () => PublicDocument::token($estimate));
}

function approveOnline(string $token, array $data = [])
{
    app(CurrentCompany::class)->forget();

    return test()->post(route('documents.public.approve', $token), [
        'signer_name' => 'Maria Garcia',
        'signature_type' => 'drawn',
        'signature' => 'data:image/png;base64,'.base64_encode(signaturePng()),
        'selected_items' => [],
        ...$data,
    ]);
}

beforeEach(function () {
    Storage::fake(PrivateMedia::diskName());
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner, ['phone' => '+16045550111']);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id, 'name' => 'Doctor Appliance']);
    $customer = Customer::factory()->for($this->company)->withEmail('maria@example.com')->create(['first_name' => 'Maria', 'last_name' => 'Garcia']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $this->brand->id]);
    $this->tax = inCompany($this->company, fn () => $this->company->taxRates()->create(['name' => 'Tax', 'rate' => 10, 'is_active' => true]));

    $this->actingAs($this->owner);
});

test('the online page offers approve and decline with the numbers to recalculate optional lines', function () {
    $estimate = approvalEstimate($this->company, $this->job, ['tax_rate_ids' => [$this->tax->id]]);

    // The optional line is not in the total until picked: 100 + 20 + 10% of 100.
    expect($estimate->total)->toBe(13000);

    auth()->logout();
    $this->get(route('documents.public', estimateToken($this->company, $estimate)))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/document')
            ->where('estimate.can_approve', true)
            ->where('estimate.can_decline', true)
            ->where('estimate.can_pay_deposit', false)
            ->where('estimate.calc.items.2.optional', true)
            ->where('estimate.calc.items.2.selected', false)
            ->where('estimate.calc.taxes.0.rate', '10')
            ->where('document.items.2.included', false)
            ->where('document.approval.expired', false));
});

test('the customer approves with a drawn signature, picking an optional line', function () {
    $owner2 = memberOf($this->company, UserRole::Admin);
    $tech = memberOf($this->company, UserRole::Technician);
    $estimate = approvalEstimate($this->company, $this->job, ['tax_rate_ids' => [$this->tax->id]]);
    $optional = inCompany($this->company, fn () => $estimate->items()->where('optional', true)->value('id'));
    $token = estimateToken($this->company, $estimate);

    approveOnline($token, ['selected_items' => [$optional]])->assertRedirect(route('documents.public', $token));

    $estimate = inCompany($this->company, fn () => $estimate->fresh('items'));
    expect($estimate->status)->toBe(EstimateStatus::Approved)
        ->and($estimate->approved_at)->not->toBeNull()
        ->and($estimate->signer_name)->toBe('Maria Garcia')
        ->and($estimate->signature_type)->toBe('drawn')
        ->and($estimate->approved_ip)->toBe('127.0.0.1')
        ->and($estimate->approvedOnline())->toBeTrue()
        // 100 + 20 + 50, tax 10% of 150.
        ->and($estimate->total)->toBe(18500)
        ->and($estimate->items->firstWhere('id', $optional)->selected)->toBeTrue();
    Storage::disk(PrivateMedia::diskName())->assertExists($estimate->signature_path);
    expect(str_starts_with(Storage::disk(PrivateMedia::diskName())->get($estimate->signature_path), "\x89PNG"))->toBeTrue();

    expect(AuditLog::query()->where('action', 'estimate.approved_online')->exists())->toBeTrue();

    // The office is told by email; technicians are not.
    Notification::assertSentTo([$this->owner, $owner2], EstimateDecided::class, fn (EstimateDecided $n) => $n->approved
        && $n->details['number'] === $estimate->number
        && $n->details['signer'] === 'Maria Garcia');
    Notification::assertNotSentTo($tech, EstimateDecided::class);

    // The page now shows the approval and no buttons.
    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
        ->where('estimate.can_approve', false)
        ->where('estimate.can_decline', false)
        ->where('document.status', 'approved')
        ->where('document.approval.online', true)
        ->where('document.approval.signer_name', 'Maria Garcia')
        ->where('document.approval.signature', fn ($uri) => str_starts_with($uri, 'data:image/png;base64,')));
});

test('the customer can type their name instead of drawing', function () {
    $estimate = approvalEstimate($this->company, $this->job);
    $token = estimateToken($this->company, $estimate);

    approveOnline($token, ['signature_type' => 'typed', 'signature' => null, 'signer_name' => 'M. Garcia'])->assertRedirect();

    $estimate = inCompany($this->company, fn () => $estimate->fresh());
    expect($estimate->status)->toBe(EstimateStatus::Approved)
        ->and($estimate->signature_type)->toBe('typed')
        ->and($estimate->signature_path)->toBeNull()
        ->and($estimate->signer_name)->toBe('M. Garcia');
});

test('a drawn signature must be a PNG and a name is required', function () {
    $estimate = approvalEstimate($this->company, $this->job);
    $token = estimateToken($this->company, $estimate);

    approveOnline($token, ['signature' => 'data:image/png;base64,'.base64_encode('<svg/>')])->assertSessionHasErrors('signature');
    approveOnline($token, ['signature' => null])->assertSessionHasErrors('signature');
    approveOnline($token, ['signer_name' => ''])->assertSessionHasErrors('signer_name');

    expect(inCompany($this->company, fn () => $estimate->fresh()->status))->toBe(EstimateStatus::Draft);
});

test('an expired estimate cannot be approved, but can still be declined', function () {
    $estimate = approvalEstimate($this->company, $this->job, ['valid_until' => '2026-10-13', 'issued_on' => '2026-10-01']);
    $token = estimateToken($this->company, $estimate);

    auth()->logout();
    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
        ->where('estimate.can_approve', false)
        ->where('estimate.can_decline', true)
        ->where('document.approval.expired', true));

    approveOnline($token)->assertSessionHasErrors('estimate');
    expect(inCompany($this->company, fn () => $estimate->fresh()->status))->toBe(EstimateStatus::Draft);

    // Valid until today (company time zone) is still fine.
    $this->actingAs($this->owner);
    $current = approvalEstimate($this->company, $this->job, ['valid_until' => '2026-10-14']);
    expect($current->id)->not->toBe($estimate->id);
    approveOnline(estimateToken($this->company, $current))->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $current->fresh()->status))->toBe(EstimateStatus::Approved);
});

test('the customer declines with a reason; the office is told and they can still change their mind', function () {
    $estimate = approvalEstimate($this->company, $this->job);
    $token = estimateToken($this->company, $estimate);

    app(CurrentCompany::class)->forget();
    $this->post(route('documents.public.decline', $token), ['reason' => 'Too expensive, buying a new washer'])->assertRedirect();

    $estimate = inCompany($this->company, fn () => $estimate->fresh());
    expect($estimate->status)->toBe(EstimateStatus::Declined)
        ->and($estimate->decline_reason)->toBe('Too expensive, buying a new washer')
        ->and($estimate->declined_ip)->toBe('127.0.0.1');
    Notification::assertSentTo($this->owner, EstimateDecided::class, fn (EstimateDecided $n) => ! $n->approved
        && $n->details['reason'] === 'Too expensive, buying a new washer');

    // Declining twice is refused; approving later is fine.
    $this->post(route('documents.public.decline', $token))->assertSessionHasErrors('estimate');
    approveOnline($token)->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $estimate->fresh()->status))->toBe(EstimateStatus::Approved);
});

test('an approved or invoiced estimate cannot be answered again', function () {
    $estimate = approvalEstimate($this->company, $this->job);
    $token = estimateToken($this->company, $estimate);
    approveOnline($token)->assertSessionHasNoErrors();

    approveOnline($token)->assertSessionHasErrors('estimate');
    $this->post(route('documents.public.decline', $token))->assertSessionHasErrors('estimate');
});

test('a signed estimate is locked for edits and converts to an invoice in one tap with the picked lines', function () {
    $estimate = approvalEstimate($this->company, $this->job, ['tax_rate_ids' => [$this->tax->id]]);
    $token = estimateToken($this->company, $estimate);
    approveOnline($token)->assertSessionHasNoErrors();

    $this->actingAs($this->owner);
    $this->get(route('estimates.show', $estimate))->assertInertia(fn (Assert $page) => $page
        ->where('can.update', false)
        ->where('can.delete', false)
        ->where('can.convert', true)
        ->where('document.online_approval.signer_name', 'Maria Garcia')
        ->where('document.items.2.optional', true)
        ->where('document.items.2.selected', false));
    $this->get(route('estimates.edit', $estimate))->assertForbidden();
    $this->put(route('estimates.update', $estimate), documentPayload())->assertForbidden();
    $this->put(route('estimates.decide', $estimate), ['approved' => false])->assertForbidden();

    $this->post(route('estimates.convert', $estimate))->assertRedirect();

    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    // The optional line the customer did not pick is left out.
    expect($invoice->items->pluck('description')->all())->toBe(['Replace drain pump', 'Disposal fee'])
        ->and($invoice->total)->toBe(13000)
        ->and(inCompany($this->company, fn () => $estimate->fresh()->status))->toBe(EstimateStatus::Invoiced);
});

test('the PDF of an approved estimate has the signature and the approval date', function () {
    $estimate = approvalEstimate($this->company, $this->job);
    approveOnline(estimateToken($this->company, $estimate))->assertSessionHasNoErrors();

    $html = inCompany($this->company, fn () => view('pdf.document', [
        'doc' => DocumentPrint::data($estimate->fresh(), embedLogo: true),
        'url' => null,
    ])->render());

    expect($html)->toContain('Approved by the customer')
        ->toContain('data:image/png;base64,')
        ->toContain('Signed by Maria Garcia on Oct 14, 2026')
        ->toContain('IP address: 127.0.0.1')
        ->toContain('Optional · not included');

    $this->actingAs($this->owner);
    $this->get(route('estimates.pdf', $estimate))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('staff can still record an on-site approval of an estimate that was not signed online', function () {
    $estimate = approvalEstimate($this->company, $this->job);

    $this->put(route('estimates.decide', $estimate), ['approved' => true])->assertRedirect();

    $estimate = inCompany($this->company, fn () => $estimate->fresh());
    expect($estimate->status)->toBe(EstimateStatus::Approved)->and($estimate->approvedOnline())->toBeFalse();
    $this->get(route('estimates.edit', $estimate))->assertOk();
});

test('the estimate form fills "valid until" from the company setting', function () {
    $this->company->update(['estimate_valid_days' => 14]);
    $this->get(route('estimates.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('defaultValidUntil', '2026-10-28')
        ->where('canTakeDeposit', false));

    $this->company->update(['estimate_valid_days' => null]);
    $this->get(route('estimates.create', $this->job))->assertInertia(fn (Assert $page) => $page->where('defaultValidUntil', null));
});

test('the deposit is worked out from the total, as a percent or a fixed amount', function () {
    $percent = approvalEstimate($this->company, $this->job, ['deposit_type' => 'percent', 'deposit_value' => '50']);
    expect($percent->deposit_amount)->toBe(6000);

    $amount = approvalEstimate($this->company, $this->job, ['deposit_type' => 'amount', 'deposit_value' => '500.00']);
    // Never more than the total.
    expect($amount->deposit_amount)->toBe(12000);

    $this->post(route('estimates.store', $this->job), documentPayload(['deposit_type' => 'percent', 'deposit_value' => '150']))
        ->assertSessionHasErrors('deposit_value');
});

describe('office SMS', function () {
    beforeEach(function () {
        Queue::fake();
        config(['services.twilio.account_sid' => 'ACmaster', 'services.twilio.auth_token' => 't']);
        inCompany($this->company, fn () => SmsAccount::create(['provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 's', 'phone_number' => '+16045550100']));
    });

    test('in Automatic mode the office also gets a text when an estimate is approved', function () {
        $this->company->update(['sms_mode' => 'automatic']);
        $estimate = approvalEstimate($this->company, $this->job);

        approveOnline(estimateToken($this->company, $estimate))->assertSessionHasNoErrors();

        Queue::assertPushed(SendOfficeSms::class, fn (SendOfficeSms $job) => $job->to === '+16045550111'
            && str_contains($job->body, $estimate->number)
            && str_contains($job->body, 'Maria Garcia'));
    });

    test('in other modes, and for declines, it is email only', function () {
        $estimate = approvalEstimate($this->company, $this->job);
        approveOnline(estimateToken($this->company, $estimate))->assertSessionHasNoErrors();

        $this->company->update(['sms_mode' => 'automatic']);
        $declined = approvalEstimate($this->company, $this->job);
        app(CurrentCompany::class)->forget();
        $this->post(route('documents.public.decline', estimateToken($this->company, $declined)))->assertRedirect();

        Queue::assertNotPushed(SendOfficeSms::class);
    });
});

describe('deposit through Square', function () {
    beforeEach(function () {
        config(['services.square' => [
            'environment' => 'sandbox', 'application_id' => 'app', 'application_secret' => 'secret',
            'webhook_signature_key' => 'whsec-test-key', 'webhook_url' => 'https://app.example.test/webhooks/payments/square',
            'api_version' => '2025-10-16',
        ]]);
        Http::fake([
            SQUARE_SANDBOX.'/v2/online-checkout/payment-links/*' => Http::response(['deleted_at' => now()->toIso8601String()]),
            SQUARE_SANDBOX.'/v2/online-checkout/payment-links' => Http::response(['payment_link' => [
                'id' => 'PLD1', 'version' => 1, 'url' => 'https://sandbox.square.link/u/DEP', 'order_id' => 'ORDERD1',
            ]]),
        ]);
        $this->company->update(['payment_provider' => 'square', 'currency' => 'CAD']);
        inCompany($this->company, fn () => PaymentProviderConnection::create([
            'provider' => 'square', 'account_id' => 'MLR1', 'account_name' => 'Doctor Appliance',
            'location_id' => 'LOC1', 'location_name' => 'Vancouver', 'currency' => 'CAD',
            'access_token' => 'EAAAl-access-1', 'refresh_token' => 'EQAAl-refresh-1', 'token_expires_at' => now()->addDays(30),
        ]));
    });

    function depositWebhook(string $id, int $amount, string $type = 'payment.updated', array $object = [])
    {
        $event = [
            'merchant_id' => 'MLR1', 'type' => $type, 'event_id' => (string) Str::uuid(),
            'data' => ['object' => $object ?: ['payment' => [
                'id' => $id, 'status' => 'COMPLETED', 'order_id' => 'ORDERD1', 'location_id' => 'LOC1',
                'amount_money' => ['amount' => $amount, 'currency' => 'CAD'],
                'card_details' => ['card' => ['card_brand' => 'VISA', 'last_4' => '4242']],
                'created_at' => now()->toIso8601String(),
            ]]],
        ];
        $body = json_encode($event);

        return test()->call('POST', route('webhooks.payments', 'square'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => base64_encode(hash_hmac('sha256', config('services.square.webhook_url').$body, 'whsec-test-key', true)),
        ], $body);
    }

    test('approving sends the customer to pay the deposit; the payment stays on the estimate until it is invoiced', function () {
        $this->get(route('estimates.create', $this->job))->assertInertia(fn (Assert $page) => $page->where('canTakeDeposit', true));
        $estimate = approvalEstimate($this->company, $this->job, ['deposit_type' => 'percent', 'deposit_value' => '25']);
        $token = estimateToken($this->company, $estimate);

        approveOnline($token)->assertRedirect('https://sandbox.square.link/u/DEP');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/online-checkout/payment-links')
            && $r['quick_pay']['price_money'] === ['amount' => 3000, 'currency' => 'CAD']
            && str_contains($r['quick_pay']['name'], 'Deposit for estimate')
            && ! isset($r['checkout_options']['allow_tipping']));
        $link = inCompany($this->company, fn () => InvoicePaymentLink::sole());
        expect($link->estimate_id)->toBe($estimate->id)->and($link->invoice_id)->toBeNull();

        // Until paid, the page offers to pay the deposit (the same link is reused).
        $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
            ->where('estimate.can_pay_deposit', true)
            ->where('document.approval.deposit_due_minor', 3000));
        $this->post(route('documents.public.deposit', $token))->assertRedirect('https://sandbox.square.link/u/DEP');

        // Square reports the payment (twice: delivered at least once).
        depositWebhook('PAYD1', 3000)->assertNoContent();
        depositWebhook('PAYD1', 3000)->assertNoContent();

        $payment = inCompany($this->company, fn () => Payment::sole());
        expect($payment->estimate_id)->toBe($estimate->id)
            ->and($payment->invoice_id)->toBeNull()
            ->and($payment->amount)->toBe(3000)
            ->and(inCompany($this->company, fn () => $estimate->fresh()->depositPaid()))->toBe(3000);
        $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
            ->where('estimate.can_pay_deposit', false)
            ->where('document.approval.deposit_paid', '$30.00'));

        // A paid deposit keeps the estimate from being deleted.
        $this->actingAs($this->owner);
        $this->get(route('estimates.show', $estimate))->assertInertia(fn (Assert $page) => $page
            ->where('can.delete', false)
            ->where('document.deposit_paid', 3000));

        // The deposit counts on the invoice.
        $this->post(route('estimates.convert', $estimate))->assertRedirect();
        $invoice = inCompany($this->company, fn () => Invoice::sole());
        expect($invoice->amount_paid)->toBe(3000)
            ->and($invoice->balance)->toBe(9000)
            ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
            ->and(inCompany($this->company, fn () => $payment->fresh()->invoice_id))->toBe($invoice->id);

        // Voiding the invoice gives the deposit back to the estimate, for the next invoice.
        $this->post(route('invoices.void', $invoice), ['reason' => 'Wrong lines'])->assertSessionHasNoErrors();
        expect(inCompany($this->company, fn () => $payment->fresh()->invoice_id))->toBeNull()
            ->and(inCompany($this->company, fn () => $estimate->fresh()->status))->toBe(EstimateStatus::Approved);
        $this->post(route('estimates.convert', $estimate))->assertRedirect();
        expect(inCompany($this->company, fn () => Invoice::latest('id')->first()->amount_paid))->toBe(3000);
    });

    test('a refund of a deposit not yet invoiced is recorded on the estimate', function () {
        $estimate = approvalEstimate($this->company, $this->job, ['deposit_type' => 'amount', 'deposit_value' => '40']);
        approveOnline(estimateToken($this->company, $estimate))->assertRedirect('https://sandbox.square.link/u/DEP');
        depositWebhook('PAYD1', 4000)->assertNoContent();

        depositWebhook('', 0, 'refund.updated', ['refund' => [
            'id' => 'REF1', 'status' => 'COMPLETED', 'payment_id' => 'PAYD1', 'amount_money' => ['amount' => 4000, 'currency' => 'CAD'],
            'created_at' => now()->toIso8601String(),
        ]])->assertNoContent();

        $refund = inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->sole());
        expect($refund->amount)->toBe(-4000)
            ->and($refund->estimate_id)->toBe($estimate->id)
            ->and($refund->invoice_id)->toBeNull()
            ->and(inCompany($this->company, fn () => $estimate->fresh()->depositPaid()))->toBe(0);
    });

    test('a deposit paid after the estimate was invoiced goes onto the invoice', function () {
        $estimate = approvalEstimate($this->company, $this->job, ['deposit_type' => 'amount', 'deposit_value' => '40']);
        approveOnline(estimateToken($this->company, $estimate))->assertRedirect();
        $this->actingAs($this->owner);
        $this->post(route('estimates.convert', $estimate))->assertRedirect();

        depositWebhook('PAYD1', 4000)->assertNoContent();

        $invoice = inCompany($this->company, fn () => Invoice::sole());
        expect($invoice->amount_paid)->toBe(4000)
            ->and(inCompany($this->company, fn () => Payment::sole()->estimate_id))->toBe($estimate->id);
    });
});

test('without an online payment provider the deposit is shown but approval does not go to a checkout', function () {
    $estimate = approvalEstimate($this->company, $this->job, ['deposit_type' => 'percent', 'deposit_value' => '50']);
    $token = estimateToken($this->company, $estimate);

    approveOnline($token)->assertRedirect(route('documents.public', $token));

    $this->get(route('documents.public', $token))->assertInertia(fn (Assert $page) => $page
        ->where('estimate.online_payments', false)
        ->where('estimate.can_pay_deposit', false)
        ->where('document.approval.deposit_due_minor', 6000));
    $this->post(route('documents.public.deposit', $token))->assertSessionHasErrors('pay');
});

describe('tenant isolation', function () {
    test('only the office of the estimate\'s company is told', function () {
        $other = Company::factory()->create();
        $otherOwner = memberOf($other, UserRole::Owner);
        $estimate = approvalEstimate($this->company, $this->job);

        approveOnline(estimateToken($this->company, $estimate))->assertSessionHasNoErrors();

        Notification::assertSentTo($this->owner, EstimateDecided::class);
        Notification::assertNotSentTo($otherOwner, EstimateDecided::class);
    });

    test('an admin limited to another brand is not told', function () {
        $otherBrand = Brand::factory()->create(['company_id' => $this->company->id]);
        $admin = memberOf($this->company, UserRole::Admin);
        inCompany($this->company, fn () => $admin->brands()->attach($otherBrand->id, ['company_id' => $this->company->id]));
        $estimate = approvalEstimate($this->company, $this->job);

        approveOnline(estimateToken($this->company, $estimate))->assertSessionHasNoErrors();

        Notification::assertNotSentTo($admin, EstimateDecided::class);
    });

    test('deposits on estimates are scoped to their company', function () {
        $estimate = approvalEstimate($this->company, $this->job);
        inCompany($this->company, function () use ($estimate) {
            $payment = new Payment(['amount' => 1000, 'method' => 'online', 'received_at' => now(), 'provider' => 'square', 'provider_payment_id' => 'X1']);
            $payment->estimate_id = $estimate->id;
            $payment->currency = $estimate->currency;
            $payment->save();
        });

        expect(inCompany(Company::factory()->create(), fn () => Payment::query()->count()))->toBe(0)
            ->and(inCompany($this->company, fn () => Payment::query()->count()))->toBe(1);
    });

    test('an invoice token cannot approve, and a bad token is not found', function () {
        $this->post(route('invoices.store', $this->job), documentPayload());
        $invoice = inCompany($this->company, fn () => Invoice::sole());
        $token = inCompany($this->company, fn () => PublicDocument::token($invoice));

        approveOnline($token)->assertNotFound();
        approveOnline('e'.Str::random(47))->assertNotFound();
    });
});
