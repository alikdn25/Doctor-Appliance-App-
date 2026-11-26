<?php

use App\Actions\Billing\RecordPayment;
use App\Actions\Jobs\ReopenJob;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Mail\CustomerMessageMail;
use App\Mail\ReceiptMail;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ReviewRequest;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Support\Jobs\MorningSms;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * One window to close a job (owner's task of November 26): "Complete" and "⋯ → Close job" open Close job — Paid —
 * Cash, Paid — E-transfer, Take card payment, Send invoice, No charge; then "Receipt & review" and Done: the payment
 * is recorded, the open visit finished, the job closed, Today opens and the emails go from the queue. Paid online,
 * the job closes by itself even with a visit open; jobs:close-paid closes the paid jobs left open.
 */

beforeEach(function () {
    Mail::fake();
    // 11:00 in Vancouver.
    $this->travelTo('2031-03-10 18:00:00');
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver', 'country' => 'CA', 'currency' => 'CAD', 'prices_include_tax' => false]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->gst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true]);
    $this->pst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 1]);
    $this->profile = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Surrey', 'review_url' => 'https://g.page/r/doctor/review']));
    $this->customer = Customer::factory()->for($this->company)->withEmail('jane@example.com')->create(['first_name' => 'Jane']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))
        ->create(['brand_id' => $this->brand->id, 'status' => JobStatus::InProgress]);
    // The visit under way, started an hour ago.
    $this->visit = JobVisit::factory()->for($this->job, 'job')->assignedTo($this->owner)->create([
        'scheduled_start' => now()->subHour(), 'scheduled_end' => now()->addHour(),
        'status' => VisitStatus::InProgress, 'started_at' => now()->subHour(),
    ]);
    $this->actingAs($this->owner);
});

function closeOut(array $data = []): TestResponse
{
    return test()->post(route('jobs.close-out', test()->job), [
        'token' => 'tap-1', 'method' => 'cash', 'note' => null,
        'send_receipt' => false, 'request_review' => false, 'google_profile_id' => null, 'email' => null,
        ...$data,
    ]);
}

function todayJobIds(): array
{
    $ids = [];
    test()->get(route('jobs.mine'))->assertInertia(function (Assert $page) use (&$ids) {
        $ids = collect($page->toArray()['props']['visits'])->pluck('job.id')->all();
    });

    return $ids;
}

test('the job page gives the window: taxes, no invoice yet, the customer email and the review location', function () {
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('closeSheet.invoice', null)
        ->where('closeSheet.taxes.0.name', 'GST')
        ->where('closeSheet.taxes.1.name', 'PST')
        ->where('closeSheet.customer_email', 'jane@example.com')
        ->where('closeSheet.default_profile_id', $this->profile->id)
        ->where('closeSheet.close_url', route('jobs.close-out', $this->job)));
});

test('Paid — Cash with lines: invoice with taxes on top, paid, visit finished, job closed, Today opens', function () {
    expect(todayJobIds())->toBe([$this->job->id]);

    closeOut(['lines' => [
        ['description' => 'Drain pump', 'amount' => '100', 'tax_rate_ids' => [$this->gst->id, $this->pst->id]],
        ['description' => 'Labour', 'amount' => '50', 'tax_rate_ids' => [$this->gst->id]],
    ], 'note' => 'Replaced the pump'])->assertRedirect('/today');

    $invoice = inCompany($this->company, fn () => Invoice::sole());
    // 100 + 12 % and 50 + 5 %: 164.50.
    expect($invoice->total)->toBe(16450)->and($invoice->status)->toBe(InvoiceStatus::Paid);
    $payment = inCompany($this->company, fn () => Payment::sole());
    expect($payment->method)->toBe(PaymentMethod::Cash)->and($payment->amount)->toBe(16450);

    $job = $this->job->fresh();
    expect($job->status)->toBe(JobStatus::Paid)
        ->and($job->outcome)->toBe(JobOutcome::Repaired)
        ->and($job->closed_at)->not->toBeNull()
        ->and($job->tech_notes)->toContain('Replaced the pump')
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed)
        ->and($this->visit->fresh()->finished_at)->not->toBeNull();
    expect(todayJobIds())->toBe([]);
    Mail::assertNothingQueued();
});

test('Paid — E-transfer pays the open invoice in full; no second invoice', function () {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('closeSheet.invoice.total', fn ($total) => str_contains($total, '280.50')));

    closeOut(['method' => 'bank_transfer', 'lines' => [['description' => 'Ignored', 'amount' => '99']]])->assertRedirect('/today');

    $invoice = inCompany($this->company, fn () => Invoice::sole());
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    $payment = inCompany($this->company, fn () => Payment::sole());
    expect($payment->method)->toBe(PaymentMethod::BankTransfer)->and($payment->amount)->toBe(28050);
    expect($this->job->fresh())->status->toBe(JobStatus::Paid)->closed_at->not->toBeNull();
});

test('Paid — Cash without an amount closes the job without an invoice', function () {
    closeOut()->assertRedirect('/today');

    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(0);
    expect($this->job->fresh())->status->toBe(JobStatus::Completed)->outcome->toBe(JobOutcome::Repaired);
    expect(todayJobIds())->toBe([]);
});

test('Take card payment: the invoice from the lines, the reader payment, then Done closes the job', function () {
    $this->postJson(route('jobs.close-out.invoice', $this->job), ['lines' => [['description' => 'Repair', 'amount' => '200', 'tax_rate_ids' => [$this->gst->id]]]])
        ->assertOk()->assertJsonPath('balance', 21000);
    $invoice = inCompany($this->company, fn () => Invoice::sole());

    // The reader payment (recorded by the charge as on the Take payment screen).
    $payment = inCompany($this->company, fn () => app(RecordPayment::class)->fromProvider($invoice, 'square', 'SQ-1', 21000, now(), null, 'CAD', 0, PaymentMethod::CardReader, $this->owner));
    // Paid on site with the visit still under way: open until Done.
    expect($this->job->fresh()->closed_at)->toBeNull();

    closeOut(['method' => 'card_reader', 'payment_id' => $payment->id, 'send_receipt' => true])->assertRedirect('/today');

    expect(inCompany($this->company, fn () => Payment::count()))->toBe(1);
    expect($this->job->fresh())->status->toBe(JobStatus::Paid)->closed_at->not->toBeNull();
    Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->hasTo('jane@example.com') && $mail->reviewProfileId === null);
});

test('Take card payment refuses a payment of another job', function () {
    $other = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id]);
    $this->post(route('invoices.store', $other), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $payment = inCompany($this->company, fn () => app(RecordPayment::class)->manual($invoice, 1000, PaymentMethod::Cash, null, null, now(), $this->owner));

    closeOut(['method' => 'card_reader', 'payment_id' => $payment->id])->assertSessionHasErrors('payment_id');
    expect($this->job->fresh()->closed_at)->toBeNull();
});

test('Send invoice: the invoice from the lines, sent by email, the job awaits payment (as before)', function () {
    $this->postJson(route('jobs.close-out.invoice', $this->job), ['lines' => [['description' => 'Repair', 'amount' => '120']]])
        ->assertOk()->assertJsonPath('url', fn ($url) => str_contains($url, '/invoices/'));
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    expect($this->job->fresh()->closed_at)->toBeNull();

    $this->post(route('invoices.send', $invoice), ['email' => 'jane@example.com', 'message' => 'Your invoice'])->assertSessionHasNoErrors();

    expect($this->job->fresh()->status)->toBe(JobStatus::AwaitingPayment)
        ->and($this->visit->fresh()->status)->toBe(VisitStatus::Completed);
    expect(todayJobIds())->toBe([]);
});

test('Take card payment without an amount and no invoice is refused', function () {
    $this->postJson(route('jobs.close-out.invoice', $this->job), ['lines' => []])->assertStatus(422);
    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(0);
});

test('No charge closes without payment; Request review sends the review email only', function () {
    closeOut(['method' => 'no_charge', 'request_review' => true, 'google_profile_id' => $this->profile->id, 'send_receipt' => true])
        ->assertRedirect('/today');

    expect($this->job->fresh())->outcome->toBe(JobOutcome::NoCharge)->closed_at->not->toBeNull();
    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(0);
    Mail::assertQueued(CustomerMessageMail::class, 1);
    Mail::assertNotQueued(ReceiptMail::class);
    expect(inCompany($this->company, fn () => ReviewRequest::sole())->service_job_id)->toBe($this->job->id);
});

test('Send receipt and Request review: the four combinations', function (bool $receipt, bool $review, string $expect) {
    closeOut(['lines' => [['description' => 'Repair', 'amount' => '100']], 'send_receipt' => $receipt, 'request_review' => $review,
        'google_profile_id' => $this->profile->id])->assertRedirect('/today');

    match ($expect) {
        'both' => (function () {
            // One email: the receipt with the review block.
            Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $m) => $m->reviewProfileId === $this->profile->id && str_contains((string) $m->reviewText, 'g.page'));
            Mail::assertNotQueued(CustomerMessageMail::class);
        })(),
        'receipt' => (function () {
            Mail::assertQueued(ReceiptMail::class, fn (ReceiptMail $m) => $m->reviewProfileId === null);
            Mail::assertNotQueued(CustomerMessageMail::class);
        })(),
        'review' => (function () {
            Mail::assertNotQueued(ReceiptMail::class);
            Mail::assertQueued(CustomerMessageMail::class, 1);
        })(),
        'none' => Mail::assertNothingQueued(),
    };
    expect($this->job->fresh()->closed_at)->not->toBeNull();
})->with([
    'both' => [true, true, 'both'],
    'receipt only' => [true, false, 'receipt'],
    'review only' => [false, true, 'review'],
    'none' => [false, false, 'none'],
]);

test('a customer without email: the email typed is saved and used; without one nothing goes but the job closes', function () {
    inCompany($this->company, fn () => $this->customer->emails()->delete());

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('closeSheet.customer_email', null));

    closeOut(['request_review' => true, 'google_profile_id' => $this->profile->id])->assertRedirect('/today');
    Mail::assertNothingQueued();
    expect($this->job->fresh()->closed_at)->not->toBeNull();

    // Another job of the same customer, with the email typed this time.
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    closeOut(['token' => 'tap-2', 'request_review' => true, 'google_profile_id' => $this->profile->id, 'email' => 'Jane.New@Example.com'])->assertRedirect('/today');

    expect(inCompany($this->company, fn () => $this->customer->emails()->pluck('email')->all()))->toBe(['jane.new@example.com']);
    Mail::assertQueued(CustomerMessageMail::class, fn ($m) => $m->hasTo('jane.new@example.com'));
});

test('Done pressed twice: one payment, one invoice, one email', function () {
    $data = ['lines' => [['description' => 'Repair', 'amount' => '100']], 'send_receipt' => true, 'request_review' => true, 'google_profile_id' => $this->profile->id];

    closeOut($data)->assertRedirect('/today');
    closeOut($data)->assertRedirect('/today');

    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(1)
        ->and(inCompany($this->company, fn () => Payment::count()))->toBe(1)
        ->and(ServiceJob::withoutGlobalScopes()->count())->toBe(1);
    Mail::assertQueued(ReceiptMail::class, 1);
});

test('the review location picked is offered first next time', function () {
    $dallas = inCompany($this->company, fn () => GoogleProfile::create(['label' => 'A Dallas', 'review_url' => 'https://g.page/r/dallas/review']));

    closeOut(['request_review' => true, 'google_profile_id' => $dallas->id]);

    $next = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    $this->get(route('jobs.show', $next))->assertInertia(fn (Assert $page) => $page->where('closeSheet.default_profile_id', $dallas->id));
});

test('after Take payment, Done on the "Receipt & review" step closes the job and opens Today', function () {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->postJson(route('charges.store'), ['method' => 'cash', 'invoice_id' => $invoice->id])->assertOk()->assertJsonPath('status', 'paid');
    $payment = inCompany($this->company, fn () => Payment::sole());

    // Still open: the technician is on site.
    expect($this->job->fresh()->closed_at)->toBeNull();
    $this->get(route('payments.paid', $payment))->assertInertia(fn (Assert $page) => $page
        ->where('close_step.done_url', route('payments.done', $payment))
        ->where('close_step.customer_email', 'jane@example.com'));

    $this->post(route('payments.done', $payment), ['token' => 'paid-1', 'send_receipt' => true, 'request_review' => true, 'google_profile_id' => $this->profile->id])
        ->assertRedirect('/today');
    $this->post(route('payments.done', $payment), ['token' => 'paid-1', 'send_receipt' => true, 'request_review' => true, 'google_profile_id' => $this->profile->id])
        ->assertRedirect('/today');

    expect($this->job->fresh())->status->toBe(JobStatus::Paid)->closed_at->not->toBeNull();
    expect($this->visit->fresh()->status)->toBe(VisitStatus::Completed);
    Mail::assertQueued(ReceiptMail::class, 1);
});

test('paid online: the job closes by itself even with the visit open; the visit ends at the payment time', function () {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $paidAt = now()->subMinutes(10);

    inCompany($this->company, fn () => app(RecordPayment::class)->fromProvider($invoice, 'square', 'ONLINE-1', 28050, $paidAt));

    $job = $this->job->fresh();
    expect($job->status)->toBe(JobStatus::Paid)->and($job->closed_at)->not->toBeNull();
    $visit = $this->visit->fresh();
    expect($visit->status)->toBe(VisitStatus::Completed)->and($visit->finished_at->equalTo($paidAt))->toBeTrue();
    expect(todayJobIds())->toBe([]);
    Mail::assertNothingQueued();
});

test('a reopened job with a new visit is not closed by the payment of its old invoice; a refund does not reopen a job', function () {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    closeOut(['method' => 'no_charge'])->assertSessionHasNoErrors();
    expect($this->job->fresh()->closed_at)->not->toBeNull();

    inCompany($this->company, fn () => app(ReopenJob::class)->handle($this->job->fresh(), [
        'attributes' => ['scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour()],
        'assignee_ids' => [$this->owner->id],
    ], null, $this->owner));
    expect($this->job->fresh()->closed_at)->toBeNull();

    $payment = inCompany($this->company, fn () => app(RecordPayment::class)->fromProvider($invoice, 'square', 'OLD-1', 28050, now()));
    expect($this->job->fresh())->closed_at->toBeNull()->status->toBe(JobStatus::Scheduled);

    // A paid job refunded stays closed.
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $second = inCompany($this->company, fn () => Invoice::query()->where('service_job_id', $this->job->id)->sole());
    $paid = inCompany($this->company, fn () => app(RecordPayment::class)->fromProvider($second, 'square', 'NEW-1', 28050, now()));
    expect($this->job->fresh()->closed_at)->not->toBeNull();
    inCompany($this->company, fn () => app(RecordPayment::class)->refundFromProvider($paid, 'REF-1', 28050, now()));
    $job = $this->job->fresh();
    expect($job->closed_at)->not->toBeNull()->and($job->status->isLocked() || in_array($job->status, [JobStatus::Invoiced, JobStatus::Paid, JobStatus::Completed], true))->toBeTrue();
    expect(todayJobIds())->not->toContain($this->job->id);
});

test('another company cannot close the job', function () {
    $other = Company::factory()->create();
    $this->actingAs(memberOf($other, UserRole::Owner));

    closeOut()->assertNotFound();
    expect($this->job->fresh()->closed_at)->toBeNull();
});

test('a job is listed once on Today even with two visits that day', function () {
    JobVisit::factory()->for($this->job, 'job')->assignedTo($this->owner)
        ->create(['scheduled_start' => now()->addHours(2), 'scheduled_end' => now()->addHours(3)]);

    expect(todayJobIds())->toBe([$this->job->id]);
});

test('Today is the start: manifest, home, the old address and sign-in', function () {
    $this->get('/manifest.webmanifest')->assertJsonPath('start_url', '/today');
    $this->get('/')->assertRedirect(route('jobs.mine'));
    expect(route('jobs.mine', absolute: false))->toBe('/today');
    $this->get('/my-jobs?date=2031-03-11')->assertRedirect('/today?date=2031-03-11');
    $this->get('/today')->assertInertia(fn (Assert $page) => $page->component('jobs/mine'));

    auth()->logout();
    $tech = memberOf($this->company, UserRole::Technician, ['email' => 'tom@example.com', 'password' => 'secret-pass-1']);
    // A page asked for before signing in is not where the app opens.
    $this->get(route('jobs.index'));
    $this->post(route('login.store'), ['email' => 'tom@example.com', 'password' => 'secret-pass-1'])->assertRedirect('/today');
    expect(auth()->id())->toBe($tech->id);
});

test('jobs:close-paid closes paid jobs left open, finishes the visit at the payment time, sends nothing', function () {
    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::sole());
    $this->travel(1)->minutes();
    inCompany($this->company, fn () => app(RecordPayment::class)->manual($invoice, 28050, PaymentMethod::Cash, null, null, now(), $this->owner));
    $paidAt = now()->toImmutable();
    // Paid on site, but nobody pressed Done: the job stayed open with the visit running.
    expect($this->job->fresh()->closed_at)->toBeNull();

    // Paid, but a visit was booked after the payment: left open.
    $later = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    $this->post(route('invoices.store', $later), documentPayload())->assertSessionHasNoErrors();
    $laterInvoice = inCompany($this->company, fn () => Invoice::query()->where('service_job_id', $later->id)->sole());
    inCompany($this->company, function () use ($later, $laterInvoice) {
        $later->forceFill(['reopened_at' => now()])->save();
        app(RecordPayment::class)->manual($laterInvoice, 28050, PaymentMethod::Cash, null, null, now(), $this->owner);
    });
    $this->travel(30)->minutes();
    JobVisit::factory()->for($later, 'job')->assignedTo($this->owner)->create(['scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour()]);

    $this->artisan('jobs:close-paid', ['--dry-run' => true])->assertSuccessful();
    expect($this->job->fresh()->closed_at)->toBeNull();

    $this->artisan('jobs:close-paid')->assertSuccessful()->expectsOutputToContain('Closed: 1');

    $job = $this->job->fresh();
    expect($job->status)->toBe(JobStatus::Paid)->and($job->closed_at)->not->toBeNull();
    expect($this->visit->fresh()->finished_at->equalTo($paidAt))->toBeTrue();
    expect($later->fresh()->closed_at)->toBeNull();
    expect(AuditLog::query()->withoutGlobalScopes()->where('action', 'job.closed_paid')->where('auditable_id', $this->job->id)->exists())->toBeTrue();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('a job closed with No charge is not counted in "No invoice"; one closed without an amount is', function () {
    closeOut(['method' => 'no_charge'])->assertRedirect('/today');
    $count = fn () => inCompany($this->company, fn () => MorningSms::completedWithoutInvoice()->count());
    expect($count())->toBe(0);

    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id, 'status' => JobStatus::Scheduled]);
    closeOut(['token' => 'tap-2'])->assertRedirect('/today');
    expect($count())->toBe(1);
});
