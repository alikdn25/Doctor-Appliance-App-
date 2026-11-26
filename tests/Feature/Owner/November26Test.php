<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Billing\SyncJobBillingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Mail\RefundReceiptMail;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\JobProfit;
use App\Support\Reports\TaxReserve;
use App\Support\Statistics\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 26: "+" menu, tasks through the assistant, search over open and closed jobs, Reopen job,
 * Invoices filters, refunds with taxes, empty invoices as drafts.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver', 'currency' => 'CAD', 'country' => 'CA']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->gst = inCompany($this->company, fn () => $this->company->taxRates()->create(['name' => 'GST', 'rate' => 5, 'is_active' => true, 'sort_order' => 1]));
    $this->pst = inCompany($this->company, fn () => $this->company->taxRates()->create(['name' => 'PST', 'rate' => 7, 'is_active' => true, 'sort_order' => 2]));
    $this->customer = Customer::factory()->for($this->company)->withPhone('+1 (604) 555-7378')->create(['first_name' => 'Maria', 'last_name' => 'Lopez']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '6630 Telford Ave', 'unit' => '1205', 'city' => 'Burnaby']);
    $this->actingAs($this->owner);
});

/** A job of the customer with a $100 invoice plus GST and PST ($112), paid in cash. */
function paidJob(string $method = 'cash'): array
{
    $test = test();
    $job = ServiceJob::factory()->for($test->property)->create(['brand_id' => $test->brand->id, 'status' => 'completed']);
    $test->post(route('invoices.store', $job), documentPayload([
        'tax_rate_ids' => [$test->gst->id, $test->pst->id],
        'items' => [['description' => 'Dryer repair', 'quantity' => '1', 'unit_price' => '100.00', 'taxable' => true, 'tax_rate_ids' => [$test->gst->id, $test->pst->id]]],
    ]))->assertSessionHasNoErrors();
    $invoice = inCompany($test->company, fn () => Invoice::query()->where('service_job_id', $job->id)->sole());
    $test->post(route('payments.store', $invoice), ['amount' => '112.00', 'method' => $method, 'received_on' => '2026-11-26'])->assertSessionHasNoErrors();
    $test->post(route('jobs.close', $job), ['outcome' => 'repaired']);

    return [$job->fresh(), inCompany($test->company, fn () => $invoice->fresh())];
}

test('the "+" menu has no "Booking from photo"; Task sits right above the booking buttons', function () {
    $fab = file_get_contents(resource_path('js/components/create-fab.tsx'));

    expect($fab)->not->toContain('booking-photo')
        ->and($fab)->not->toContain('booking_from_photo')
        ->and(file_get_contents(lang_path('en/nav.php')))->not->toContain('booking_from_photo');

    $at = fn (string $needle) => strpos($fab, $needle);
    expect($at("key: 'trip'"))->toBeLessThan($at("key: 'expense'"))
        ->and($at("key: 'expense'"))->toBeLessThan($at("key: 'charge'"))
        ->and($at("key: 'charge'"))->toBeLessThan($at("key: 'task'"))
        ->and($at("key: 'task'"))->toBeLessThan($at('<BookingButtons'))
        ->and($at('<BookingButtons'))->toBeLessThan($at("key: 'assistant'"));
});

test('the task sheet offers "✨ Assistant" only to people with the assistant', function () {
    $sheet = file_get_contents(resource_path('js/components/tasks/task-sheet.tsx'));
    expect($sheet)->toContain('const assistant = !task && auth.can?.useAssistant === true;')
        ->and($sheet)->toContain('data-test="task-manually"');

    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('auth.can.useAssistant', false));
    $this->actingAs($this->owner)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('auth.can.useAssistant', true));
});

test('a search finds open and closed jobs by the last 4 digits of the phone, the house number and the street', function () {
    [$paid] = paidJob();
    $open = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'new']);
    $other = Customer::factory()->for($this->company)->withPhone('604-555-1111')->create();
    ServiceJob::factory()->for(Property::factory()->for($other)->create(['line1' => '1 Main St', 'city' => 'Surrey', 'postal_code' => 'V3T 1A1']))->create(['brand_id' => $this->brand->id]);

    expect($paid->status)->toBe(JobStatus::Paid);

    foreach (['7378', '(604) 555-7378', '+16045557378', '604-555-7378'] as $search) {
        $this->get(route('jobs.index', ['search' => $search]))->assertInertia(fn (Assert $page) => $page
            ->has('jobs.data', 2)
            // Open first, then closed.
            ->where('jobs.data.0.id', $open->id)
            ->where('jobs.data.0.search_status', 'open')
            ->where('jobs.data.1.id', $paid->id)
            ->where('jobs.data.1.search_status', 'paid'));
    }

    foreach (['6630', 'telford', '6630 Telford', '1205', 'burnaby'] as $search) {
        $this->get(route('jobs.index', ['search' => $search]))->assertInertia(fn (Assert $page) => $page
            ->has('jobs.data', 2)
            ->where('jobs.data.1.id', $paid->id));
    }

    // The All tab's "open" filter does not narrow a search.
    $this->get(route('jobs.index', ['tab' => 'all', 'status' => 'open', 'search' => '7378']))->assertInertia(fn (Assert $page) => $page->has('jobs.data', 2));
});

test('"Reopen job" shows on a closed job only; reopening a paid job books a visit and keeps it open until it is completed', function () {
    [$job, $invoice] = paidJob();
    $open = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'scheduled']);

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->where('can.reopen', true));
    $this->get(route('jobs.show', $open))->assertInertia(fn (Assert $page) => $page->where('can.reopen', false));
    expect(file_get_contents(resource_path('js/pages/jobs/show.tsx')))->toContain('data-test="job-reopen"');

    // "Now": the visit starts at once, so nothing is booked ahead — the paid invoice still must not close it.
    $this->post(route('jobs.reopen', $job), [
        'date' => '2026-11-26', 'start_time' => '12:00', 'end_time' => '14:00', 'assignee_ids' => [$this->owner->id],
    ])->assertSessionHasNoErrors()->assertRedirect(route('jobs.show', $job));

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Scheduled)
        ->and($job->outcome)->toBeNull()
        ->and($job->reopened_at)->not->toBeNull()
        ->and(inCompany($this->company, fn () => JobVisit::query()->where('service_job_id', $job->id)->where('status', 'scheduled')->count()))->toBe(1)
        ->and(inCompany($this->company, fn () => $invoice->fresh()->status))->toBe(InvoiceStatus::Paid);

    inCompany($this->company, fn () => app(SyncJobBillingStatus::class)->handle($job->fresh(), $this->owner));
    expect($job->fresh()->status)->toBe(JobStatus::Scheduled);
    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->where('can.reopen', false));

    // Written to the status history and the change log.
    expect(inCompany($this->company, fn () => $job->statusChanges()->where('note', 'Reopened')->exists()))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'job.reopened')->exists())->toBeTrue();

    // Completed again: closed, and Paid by its invoice.
    $this->post(route('jobs.close', $job), ['outcome' => 'repaired'])->assertSessionHasNoErrors();
    expect($job->fresh()->status)->toBe(JobStatus::Paid)->and($job->fresh()->reopened_at)->toBeNull();
});

test('a job reopened without a date is New', function () {
    [$job] = paidJob();
    $this->post(route('jobs.reopen', $job), ['no_date' => true])->assertSessionHasNoErrors();

    expect($job->fresh()->status)->toBe(JobStatus::New)->and($job->fresh()->planned_date)->toBeNull();
});

test('a partial refund with GST and PST on top: the code works out the taxes and the total; no reason needed', function () {
    [$job, $invoice] = paidJob();

    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('refund.left', 11200)
        ->where('refund.taxes.0.name', 'GST')->where('refund.taxes.0.checked', true)
        ->where('refund.taxes.1.name', 'PST')->where('refund.taxes.1.checked', true));

    // More than what is left: refused.
    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '100.01', 'taxes' => ['GST', 'PST']])->assertSessionHasErrors('amount');

    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '50.00', 'taxes' => ['GST', 'PST']])->assertSessionHasNoErrors();

    $refund = inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->sole());
    expect($refund->amount)->toBe(-5600)
        ->and($refund->refund_reason)->toBeNull()
        ->and($refund->method)->toBe(PaymentMethod::Cash)
        ->and(collect($refund->refund_taxes)->pluck('amount', 'name')->all())->toBe(['GST' => 250, 'PST' => 350])
        ->and(inCompany($this->company, fn () => $invoice->fresh()->status))->toBe(InvoiceStatus::PartiallyRefunded)
        ->and($job->fresh()->status)->toBe(JobStatus::Paid);

    // Any number of refunds; the full refund is what is left.
    $this->post(route('invoices.refund', $invoice), ['mode' => 'full', 'method' => 'e_transfer'])->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => $invoice->fresh());
    expect($invoice->status)->toBe(InvoiceStatus::Refunded)
        ->and($invoice->amount_paid)->toBe(0)
        ->and(inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->latest('id')->first()->method))->toBe(PaymentMethod::BankTransfer);

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->where('job.refunded.amount', 11200));
    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '1.00'])->assertForbidden();
});

test('a refund takes its taxes and its amount back from statistics, the tax reserve and the job\'s revenue', function () {
    [$job, $invoice] = paidJob();
    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '50.00', 'taxes' => ['GST', 'PST']])->assertSessionHasNoErrors();

    $day = CarbonImmutable::parse('2026-11-26', 'America/Vancouver');
    $money = inCompany($this->company, fn () => (new Statistics($day->startOfMonth(), $day->endOfMonth()))->money());
    $taxes = collect($money['taxes'])->pluck('amount', 'name')->all();

    expect($money['total'])->toBe(5600)
        ->and($taxes)->toBe(['GST' => 250, 'PST' => 350])
        ->and(collect($money['methods'])->firstWhere('key', 'cash')['amount'])->toBe(5600);

    $reserve = inCompany($this->company, fn () => TaxReserve::month($day, $this->owner));
    expect(collect($reserve['taxes'])->pluck('collected', 'name')->all())->toBe(['GST' => 250, 'PST' => 350]);

    $profit = inCompany($this->company, fn () => JobProfit::for($job->fresh(), true));
    expect($profit['revenue'])->toBe(5000);
});

test('a card paid through Square is refunded at Square; if Square says no, it can be recorded as cash', function () {
    config(['services.square' => [
        'environment' => 'sandbox', 'application_id' => 'a', 'application_secret' => 's',
        'webhook_signature_key' => 'k', 'webhook_url' => 'https://app.example.test/webhooks/payments/square', 'api_version' => '2025-10-16',
    ]]);
    $this->company->update(['payment_provider' => 'square']);
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'completed']);
    $invoice = inCompany($this->company, function () use ($job) {
        PaymentProviderConnection::create(['provider' => 'square', 'account_id' => 'M1', 'location_id' => 'L1', 'currency' => 'CAD',
            'access_token' => 'tok', 'refresh_token' => 'r', 'token_expires_at' => now()->addDays(30)]);
        $invoice = app(SaveBillingDocument::class)->createInvoice($job, [
            'issued_on' => '2026-11-26', 'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => 10000, 'taxable' => false]],
        ], $this->owner);
        app(RecordPayment::class)->fromProvider($invoice, 'square', 'PAY1', 10000, now())->forceFill(['card_last4' => '4242'])->save();

        return $invoice;
    });

    Http::fake(['https://connect.squareupsandbox.com/v2/refunds' => Http::sequence()
        ->push(['refund' => ['id' => 'REF1', 'status' => 'PENDING']])
        ->push(['errors' => [['code' => 'REFUND_DECLINED', 'detail' => 'Interac refunds need the card on the reader']]], 400)]);

    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '30.00'])->assertSessionHasNoErrors();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/refunds') && $r['payment_id'] === 'PAY1' && $r['amount_money']['amount'] === 3000);
    $first = inCompany($this->company, fn () => Payment::query()->where('provider_payment_id', 'REF1')->sole());
    expect($first->card_last4)->toBe('4242')->and($first->refund_status)->toBe('pending');

    // Square says no: the reason comes back, nothing is recorded …
    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '20.00'])->assertSessionHasErrors('provider');
    expect(inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->count()))->toBe(1);

    // … and "Record as cash / e-transfer" records it without Square.
    $this->post(route('invoices.refund', $invoice), ['mode' => 'partial', 'amount' => '20.00', 'record_only' => true, 'method' => 'cash'])->assertSessionHasNoErrors();
    $cash = inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->latest('id')->first());
    expect($cash->method)->toBe(PaymentMethod::Cash)->and($cash->amount)->toBe(-2000)->and($cash->provider)->toBeNull();
    Http::assertSentCount(2);
});

test('"Send refund receipt" emails the refund only on the button', function () {
    Mail::fake();
    [, $invoice] = paidJob();
    $this->post(route('invoices.refund', $invoice), ['mode' => 'full'])->assertSessionHasNoErrors();
    Mail::assertNothingQueued();

    $refund = inCompany($this->company, fn () => Payment::query()->whereNotNull('refunded_payment_id')->sole());
    $this->post(route('payments.refund-receipt', $refund), ['email' => 'maria@example.com'])->assertSessionHasNoErrors();
    Mail::assertQueued(RefundReceiptMail::class, fn ($mail) => $mail->hasTo('maria@example.com'));

    $html = inCompany($this->company, fn () => (new RefundReceiptMail($refund))->render());
    expect($html)->toContain('$112.00')->toContain('GST')->toContain('PST');
});

test('a charge without a booking shows under Invoices → Paid and is found by the phone\'s and the card\'s last 4 digits', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'completed']);
    $invoice = inCompany($this->company, function () use ($job) {
        $invoice = app(SaveBillingDocument::class)->createInvoice($job, [
            'issued_on' => '2026-11-26', 'items' => [['description' => 'Dryer belt', 'quantity' => '1', 'unit_price' => 8000, 'taxable' => false]],
        ], $this->owner);
        $payment = app(RecordPayment::class)->fromProvider($invoice, 'square', 'PAY7', 8000, now());
        $payment->forceFill(['card_last4' => '9876', 'card_brand' => 'Visa', 'method' => PaymentMethod::CardReader])->save();

        return $invoice;
    });

    // Unpaid by default: not there; Paid: there, with what was done and how it was paid.
    $this->get(route('invoices.index'))->assertInertia(fn (Assert $page) => $page->where('filters.status', 'unpaid')->has('invoices.data', 0));
    $this->get(route('invoices.index', ['status' => 'paid']))->assertInertia(fn (Assert $page) => $page
        ->has('invoices.data', 1)
        ->where('invoices.data.0.id', $invoice->id)
        ->where('invoices.data.0.first_line', 'Dryer belt')
        ->where('invoices.data.0.paid_method', 'Card ····9876')
        ->where('invoices.data.0.phone', '+16045557378'));

    foreach (['7378', '9876', '80.00', $invoice->number] as $search) {
        $this->get(route('invoices.index', ['search' => $search]))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
    }
});

test('an invoice of $0 with no payments is a draft and does not close the job', function () {
    $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id, 'status' => 'in_progress']);
    $this->post(route('invoices.store', $job), documentPayload([
        'items' => [['description' => 'Free check', 'quantity' => '1', 'unit_price' => '0.00', 'taxable' => false]],
    ]))->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => Invoice::query()->where('service_job_id', $job->id)->sole());
    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($job->fresh()->status)->toBe(JobStatus::InProgress)
        ->and($job->fresh()->closed_at)->toBeNull();

    $this->get(route('invoices.index', ['status' => 'paid']))->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
});

test('"Edit by voice" on My Jobs is there when the owner works alone', function () {
    $this->company->update(['works_alone' => true]);
    ServiceJob::factory()->for($this->property)->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-11-26 15:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-11-26 17:00', 'America/Vancouver')->utc(),
    ])->create(['brand_id' => $this->brand->id, 'status' => 'scheduled']);

    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('visits.0.can_edit', true));
    expect(file_get_contents(resource_path('js/pages/jobs/mine.tsx')))->toContain('data-test="edit-by-voice"');
});
