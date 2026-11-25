<?php

use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobNote;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

function editLatestInvoice(Company $company): Invoice
{
    return inCompany($company, fn () => Invoice::query()->latest('id')->firstOrFail());
}

/*
 * Owner's task of November 25: any saved field of any record can be corrected at any time, in any status; a
 * customer's address is corrected or deleted; every correction is in the change log.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'currency' => 'CAD']);
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->withEmail('payer@example.com')->create(['first_name' => 'Paula']);
    $this->property = Property::factory()->for($this->customer)->create(['line1' => '500 Rental Ave', 'city' => 'Surrey']);
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->owner)->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);

    $this->paidInvoice = function () {
        $this->post(route('invoices.store', $this->job), documentPayload());
        $invoice = editLatestInvoice($this->company);
        $this->post(route('invoices.send', $invoice), ['email' => 'payer@example.com', 'message' => 'Thanks']);
        $this->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash']);

        return editLatestInvoice($this->company);
    };
});

test('a closed, paid job keeps every field correctable: details, status and date', function () {
    $invoice = ($this->paidInvoice)();
    expect($invoice->status)->toBe(InvoiceStatus::Paid);
    inCompany($this->company, fn () => $this->job->forceFill(['status' => JobStatus::Paid, 'closed_at' => now()])->save());

    $this->put(route('jobs.details', $this->job), [
        'first_name' => 'Pauline', 'description' => 'Dryer not heating', 'notes' => 'Side door',
        'date' => '2026-10-20', 'start_time' => '10:00', 'end_time' => '12:00',
    ])->assertSessionHasNoErrors();
    $this->put(route('jobs.status', $this->job), ['status' => 'in_progress'])->assertSessionHasNoErrors();

    $job = $this->job->fresh(['customer', 'visits']);
    expect($job->customer->first_name)->toBe('Pauline')
        ->and($job->description)->toBe('Dryer not heating')
        ->and($job->status)->toBe(JobStatus::InProgress)
        ->and($job->visits->sortByDesc('id')->first()->scheduled_start->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-10-20 10:00');
    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page->has('statusOptions'));
});

test('a sent, paid invoice can be corrected: the balance follows and Resend stands out until it goes again', function () {
    $invoice = ($this->paidInvoice)();

    $this->put(route('invoices.update', $invoice), documentPayload([
        'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '400.00', 'taxable' => false]],
    ]))->assertSessionHasNoErrors();

    $invoice = editLatestInvoice($this->company);
    expect($invoice->total)->toBe(40000)->and($invoice->balance)->toBe(40000 - 28050)
        ->and($invoice->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($invoice->edited_after_send_at)->not->toBeNull();
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page->whereNot('delivery.edited_after_send_at', null));

    $this->post(route('invoices.send', $invoice), ['email' => 'payer@example.com', 'message' => 'Corrected'])->assertSessionHasNoErrors();
    expect(editLatestInvoice($this->company)->edited_after_send_at)->toBeNull();
});

test('a payment is corrected: amount, method and date; the invoice follows; a provider payment keeps its amount', function () {
    $invoice = ($this->paidInvoice)();
    $payment = inCompany($this->company, fn () => Payment::query()->sole());

    $this->patch(route('payments.update', $payment), ['amount' => '100.00', 'method' => 'bank_transfer', 'received_on' => '2026-10-10'])
        ->assertSessionHasNoErrors();

    $payment = $payment->fresh();
    expect($payment->amount)->toBe(10000)->and($payment->method)->toBe(PaymentMethod::BankTransfer)
        ->and($payment->received_at->setTimezone('America/Vancouver')->toDateString())->toBe('2026-10-10')
        ->and(editLatestInvoice($this->company)->balance)->toBe(28050 - 10000);

    inCompany($this->company, fn () => $payment->forceFill(['provider' => 'square', 'provider_payment_id' => 'sq_1'])->save());
    $this->patch(route('payments.update', $payment), ['amount' => '1.00', 'note' => 'Card at the door'])->assertSessionHasNoErrors();
    expect($payment->fresh())->amount->toBe(10000)->provider_payment_id->toBe('sq_1')->note->toBe('Card at the door');
});

test('correcting a customer address changes it on open jobs only; closed jobs keep it as it was', function () {
    $closed = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    inCompany($this->company, fn () => $closed->forceFill(['status' => JobStatus::Completed, 'closed_at' => now()])->save());

    $this->put(route('properties.update', $this->property), ['line1' => '777 New Road', 'city' => 'Burnaby', 'country' => 'CA'])
        ->assertSessionHasNoErrors();

    inCompany($this->company, function () use ($closed) {
        expect($this->job->fresh()->property->line1)->toBe('777 New Road')
            ->and($closed->fresh()->property->line1)->toBe('500 Rental Ave')
            ->and($this->customer->properties()->pluck('line1')->all())->toBe(['777 New Road']);
    });
});

test('deleting an address of an open job breaks nothing: the job takes the customer\'s one other address', function () {
    $other = Property::factory()->for($this->customer)->create(['line1' => '9 Other St', 'city' => 'Surrey']);

    $this->delete(route('properties.destroy', $this->property))->assertSessionHasNoErrors();

    expect($this->job->fresh()->property_id)->toBe($other->id);
    $this->get(route('jobs.show', $this->job))->assertOk();
    $this->get(route('calendar'))->assertOk();
});

test('deleting the only address leaves the open job with no address', function () {
    $this->delete(route('properties.destroy', $this->property));

    $job = $this->job->fresh('property');
    expect($job->property->line1)->toBeNull();
    $this->get(route('jobs.show', $job))->assertOk();
});

test('every correction is logged: who, when, field, old and new', function () {
    $this->put(route('properties.update', $this->property), ['line1' => '1 Changed St', 'city' => 'Surrey', 'country' => 'CA']);
    $note = inCompany($this->company, function () {
        $note = new JobNote(['body' => 'Old text']);
        $note->service_job_id = $this->job->id;
        $note->user_id = $this->owner->id;
        $note->save();

        return $note;
    });
    $this->patch(route('jobs.notes.update', $note), ['body' => 'New text'])->assertSessionHasNoErrors();

    $logs = inCompany($this->company, fn () => AuditLog::query()->where('company_id', $this->company->id)->whereIn('action', ['property.updated', 'note.updated'])->get());
    $property = $logs->firstWhere('action', 'property.updated');
    expect($property->user_id)->toBe($this->owner->id)
        ->and($property->changes['old']['line1'])->toBe('500 Rental Ave')
        ->and($property->changes['new']['line1'])->toBe('1 Changed St')
        ->and($logs->firstWhere('action', 'note.updated')->changes['new']['body'])->toBe('New text');

    $this->getJson(route('history.show', ['type' => 'job', 'id' => $this->job->id]))->assertOk()
        ->assertJson(fn ($json) => $json->where('entries', fn ($entries) => collect($entries)->contains(fn ($e) => $e['type'] === 'note'))->etc());
});

test('another company cannot correct a payment or an address', function () {
    $stranger = memberOf(Company::factory()->create());
    $this->actingAs($this->owner);
    ($this->paidInvoice)();
    $payment = inCompany($this->company, fn () => Payment::query()->sole());

    $this->actingAs($stranger)->patch(route('payments.update', $payment), ['amount' => '1.00'])->assertNotFound();
    $this->actingAs($stranger)->put(route('properties.update', $this->property), ['line1' => 'Hijack'])->assertNotFound();
    expect($payment->fresh()->amount)->toBe(28050);
});
