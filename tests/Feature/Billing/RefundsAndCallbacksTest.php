<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Refunds as settled, warranty callbacks (warranties of the original job, outcomes, refund of the original job),
 * no-charge jobs.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create();
    $this->property = Property::factory()->for($this->customer)->create();
    $this->original = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'completed']);
    $this->actingAs($this->owner);

    // Original repair: labour (30 days) and a part (90 days), paid in cash.
    $this->post(route('invoices.store', $this->original), documentPayload(['items' => [
        ['description' => 'Labour', 'quantity' => '1', 'unit_price' => '100.00', 'taxable' => false],
        ['description' => 'Drain pump', 'kind' => 'part', 'quantity' => '1', 'unit_price' => '80.00', 'taxable' => false, 'unit_cost' => '40.00'],
    ]]))->assertSessionHasNoErrors();
    $this->invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    $this->post(route('payments.store', $this->invoice), ['amount' => '180.00', 'method' => 'cash', 'received_on' => '2026-10-14'])->assertSessionHasNoErrors();
    $this->post(route('jobs.close', $this->original), ['outcome' => 'repaired']);
});

function makeCallback(ServiceJob $original, $test, string $date = '2026-12-01')
{
    $test->post(route('jobs.store'), [
        'brand_id' => $test->brand->id, 'job_type' => 'warranty', 'customer_id' => $test->customer->id, 'property_id' => $test->property->id,
        'visit_type' => 'callback', 'previous_job_id' => $original->id,
        'add_visit' => true, 'visit' => ['date' => $date, 'start_time' => '09:00', 'end_time' => '11:00', 'assignee_ids' => [$test->tech->id]],
    ])->assertSessionHasNoErrors();

    return inCompany($test->company, fn () => ServiceJob::query()->latest('id')->first());
}

test('a refund as settled leaves nothing owing: partially refunded, then refunded', function () {
    $this->post(route('invoices.refund', $this->invoice), ['amount' => '50.00'])->assertSessionHasErrors('reason');
    $this->post(route('invoices.refund', $this->invoice), ['amount' => '500.00', 'reason' => 'x'])->assertSessionHasErrors('amount');

    $this->post(route('invoices.refund', $this->invoice), ['amount' => '50.00', 'reason' => 'Goodwill'])->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => $this->invoice->fresh());
    expect($invoice->status)->toBe(InvoiceStatus::PartiallyRefunded)
        ->and($invoice->balance)->toBe(0)
        ->and($invoice->amount_paid)->toBe(13000)
        ->and($invoice->credited_amount)->toBe(5000)
        ->and($this->original->fresh()->status)->toBe(JobStatus::Paid);

    $refund = inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->sole());
    expect($refund->amount)->toBe(-5000)->and($refund->method)->toBe(PaymentMethod::Cash)->and($refund->refund_reason)->toBe('Goodwill');

    $this->post(route('invoices.refund', $this->invoice), ['amount' => '130.00', 'reason' => 'Goodwill'])->assertSessionHasNoErrors();
    expect(inCompany($this->company, fn () => $this->invoice->fresh()->status))->toBe(InvoiceStatus::Refunded);
});

test('only the office refunds', function () {
    $this->actingAs($this->tech)->post(route('invoices.refund', $this->invoice), ['amount' => '10.00', 'reason' => 'x'])->assertForbidden();
});

test('a Square payment is refunded at Square and recorded once', function () {
    config(['services.square' => [
        'environment' => 'sandbox', 'application_id' => 'a', 'application_secret' => 's',
        'webhook_signature_key' => 'k', 'webhook_url' => 'https://app.example.test/webhooks/payments/square', 'api_version' => '2025-10-16',
    ]]);
    Http::fake(['https://connect.squareupsandbox.com/v2/refunds' => Http::response(['refund' => ['id' => 'REF9', 'status' => 'PENDING']])]);
    $this->company->update(['payment_provider' => 'square']);
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'completed']);
    $invoice = inCompany($this->company, function () use ($job) {
        PaymentProviderConnection::create(['provider' => 'square', 'account_id' => 'M1', 'location_id' => 'L1', 'currency' => $this->company->currency,
            'access_token' => 'tok', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(30)]);
        $invoice = app(SaveBillingDocument::class)->createInvoice($job, [
            'issued_on' => '2026-10-14', 'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => 10000, 'taxable' => false]],
        ], $this->owner);
        app(RecordPayment::class)->fromProvider($invoice, 'square', 'PAY1', 10000, now());

        return $invoice;
    });

    $this->post(route('invoices.refund', $invoice), ['amount' => '40.00', 'reason' => 'Part refunded'])->assertSessionHasNoErrors();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/refunds') && $r['payment_id'] === 'PAY1' && $r['amount_money']['amount'] === 4000);
    $refund = inCompany($this->company, fn () => Payment::query()->where('provider_payment_id', 'REF9')->sole());
    expect($refund->amount)->toBe(-4000);

    // Square's refund webhook later does not add a second row.
    inCompany($this->company, fn () => app(RecordPayment::class)->refundFromProvider(Payment::query()->where('provider_payment_id', 'PAY1')->sole(), 'REF9', 4000, now()));
    expect(inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->count()))->toBe(1)
        ->and(inCompany($this->company, fn () => $invoice->fresh()->status))->toBe(InvoiceStatus::PartiallyRefunded);
});

test('a callback shows which warranties of the original job run on the visit day', function () {
    $lines = $this->getJson(route('jobs.warranties', ['job' => $this->original->id, 'date' => '2026-12-01']))->assertOk()->json('lines');

    // Labour: 30 days (ended Nov 13); part: 90 days (until Jan 12).
    expect(collect($lines)->pluck('active', 'description')->all())->toBe(['Labour' => false, 'Drain pump' => true]);
});

test('the callback invoice starts with the original lines: free under warranty, the rest at the original price', function () {
    $callback = makeCallback($this->original, $this);

    $this->get(route('invoices.create', $callback))->assertInertia(fn (Assert $page) => $page
        ->where('prefillItems.0.description', 'Labour')
        ->where('prefillItems.0.unit_price', 10000)
        ->where('prefillItems.1.description', 'Drain pump')
        ->where('prefillItems.1.unit_price', 0));
});

test('a callback fixed under warranty is closed with that outcome', function () {
    $callback = makeCallback($this->original, $this);
    $visit = JobVisit::withoutCompanyScope()->where('service_job_id', $callback->id)->sole();
    $this->actingAs($this->tech)->post(route('visits.start', $visit));

    $this->post(route('visits.finish', $visit), ['outcome' => 'fixed_under_warranty'])->assertRedirect();

    expect($callback->fresh())->outcome->toBe(JobOutcome::FixedUnderWarranty)->status->toBe(JobStatus::Completed);
});

test('a declined callback can refund the original job; the original shows it', function () {
    $callback = makeCallback($this->original, $this);

    $this->get(route('jobs.show', $callback))->assertInertia(fn (Assert $page) => $page
        ->where('callback.number', $this->original->number)
        ->where('callback.refundable', 18000));

    $this->post(route('jobs.close', $callback), [
        'outcome' => 'customer_declined', 'reason' => 'Buying a new appliance', 'refund' => 'partial', 'refund_amount' => '80.00',
    ])->assertSessionHasErrors('refund_reason');

    $this->post(route('jobs.close', $callback), [
        'outcome' => 'customer_declined', 'reason' => 'Buying a new appliance', 'refund' => 'partial', 'refund_amount' => '80.00',
        'refund_reason' => 'Pump failed again within warranty',
    ])->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => $this->invoice->fresh());
    expect($invoice->status)->toBe(InvoiceStatus::PartiallyRefunded)
        ->and($invoice->credited_amount)->toBe(8000)
        ->and($callback->fresh()->outcome)->toBe(JobOutcome::CustomerDeclined);

    $this->get(route('jobs.show', $this->original))->assertInertia(fn (Assert $page) => $page
        ->where('job.invoices.0.status', 'partially_refunded')
        ->where('job.follow_ups.0.id', $callback->id));
});

test('unable to repair a callback with a full refund refunds everything paid', function () {
    $callback = makeCallback($this->original, $this);

    $this->post(route('jobs.close', $callback), [
        'outcome' => 'unable_to_repair', 'reason' => 'Parts no longer available', 'refund' => 'full', 'refund_reason' => 'No parts',
    ])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => $this->invoice->fresh()->status))->toBe(InvoiceStatus::Refunded);
});

test('no charge needs a reason and is only for jobs without a billed invoice', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    $this->post(route('jobs.close', $job), ['outcome' => 'no_charge'])->assertSessionHasErrors('reason');
    $this->post(route('jobs.close', $job), ['outcome' => 'no_charge', 'reason' => 'Goodwill', 'note' => 'Regular customer'])->assertSessionHasNoErrors();
    expect($job->fresh())->outcome->toBe(JobOutcome::NoCharge)->outcome_reason->toBe('Goodwill');

    $billed = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    $this->post(route('invoices.store', $billed), documentPayload());
    $this->post(route('jobs.close', $billed), ['outcome' => 'no_charge', 'reason' => 'Goodwill'])->assertSessionHasErrors('outcome');
});
