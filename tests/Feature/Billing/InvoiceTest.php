<?php

use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['invoice_prefix' => 'INV-', 'invoice_next_number' => 1042]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);

    $this->actingAs($this->owner);
});

function latestInvoice(Company $company): Invoice
{
    return inCompany($company, fn () => Invoice::latest('id')->firstOrFail());
}

function jobStatus(ServiceJob $job): JobStatus
{
    return ServiceJob::withoutCompanyScope()->find($job->id)->status;
}

test('an invoice is created from a job with the next number', function () {
    $this->post(route('invoices.store', $this->job), documentPayload(['due_on' => now()->addDays(14)->toDateString()]))
        ->assertRedirect();

    $invoice = latestInvoice($this->company);

    expect($invoice)
        ->number->toBe('INV-1042')
        ->status->toBe(InvoiceStatus::Unpaid)
        ->total->toBe(28050)
        ->amount_paid->toBe(0)
        ->balance->toBe(28050)
        ->and($this->company->fresh()->invoice_next_number)->toBe(1043);

    $this->get(route('invoices.show', $invoice))
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/show')
            ->where('document.kind', 'invoice')
            ->where('document.balance', 28050)
            ->where('can.recordPayment', true)
            ->where('can.void', true)
            ->has('paymentMethods', 5));
});

test('numbers already taken are skipped', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $this->company->update(['invoice_next_number' => 1042]);

    $this->post(route('invoices.store', $this->job), documentPayload());

    expect(latestInvoice($this->company)->number)->toBe('INV-1043');
});

test('a technician invoices their own job and sees the invoice', function () {
    $this->actingAs($this->tech)->post(route('invoices.store', $this->job), documentPayload())->assertRedirect();
    $invoice = latestInvoice($this->company);

    $this->actingAs($this->tech)->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.recordPayment', true)->where('can.void', false)->where('can.voidPayments', false));
    $this->actingAs($this->tech)->post(route('invoices.void', $invoice))->assertForbidden();
    $this->actingAs($this->tech)->get(route('invoices.index'))->assertForbidden();
});

test('creating an invoice for a completed job marks it invoiced; paying it marks it paid', function () {
    ServiceJob::withoutCompanyScope()->whereKey($this->job->id)->update(['status' => 'completed']);

    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = latestInvoice($this->company);
    expect(jobStatus($this->job))->toBe(JobStatus::Invoiced);

    $this->post(route('payments.store', $invoice), ['amount' => '100.00', 'method' => 'cash']);
    expect(jobStatus($this->job))->toBe(JobStatus::Invoiced);

    $this->post(route('payments.store', $invoice), ['amount' => '180.50', 'method' => 'e_transfer']);
    expect(jobStatus($this->job))->toBe(JobStatus::Paid)
        ->and(latestInvoice($this->company)->status)->toBe(InvoiceStatus::Paid);

    $statuses = inCompany($this->company, fn () => $this->job->statusChanges()->pluck('to_status')->map->value->all());
    expect($statuses)->toContain('invoiced', 'paid');
});

test('a job still being worked on keeps its status when invoiced, and moves on when finished', function () {
    $visit = JobVisit::withoutCompanyScope()->where('service_job_id', $this->job->id)->sole();
    $this->actingAs($this->tech)->post(route('visits.start', $visit));

    $this->actingAs($this->tech)->post(route('invoices.store', $this->job), documentPayload());
    $invoice = latestInvoice($this->company);
    $this->actingAs($this->tech)->post(route('payments.store', $invoice), ['amount' => '280.50', 'method' => 'cash']);

    expect(jobStatus($this->job))->toBe(JobStatus::InProgress);

    $this->actingAs($this->tech)->post(route('visits.finish', $visit), ['outcome' => 'completed'])->assertRedirect();

    expect(jobStatus($this->job))->toBe(JobStatus::Paid);
});

test('the total of an invoice with payments cannot drop below what was paid', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = latestInvoice($this->company);
    $this->post(route('payments.store', $invoice), ['amount' => '200.00', 'method' => 'cash']);

    $this->put(route('invoices.update', $invoice), documentPayload([
        'items' => [['description' => 'Diagnosis', 'quantity' => '1', 'unit_price' => '95.00']],
    ]))->assertSessionHasErrors('items');

    $this->put(route('invoices.update', $invoice), documentPayload([
        'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '300.00']],
    ]))->assertSessionHasNoErrors();

    expect(latestInvoice($this->company))
        ->total->toBe(30000)
        ->balance->toBe(10000)
        ->status->toBe(InvoiceStatus::PartiallyPaid)
        ->and(inCompany($this->company, fn () => AuditLog::where('action', 'invoice.total_changed')->count()))->toBe(1);
});

test('an invoice with payments cannot be voided until the payments are voided', function () {
    ServiceJob::withoutCompanyScope()->whereKey($this->job->id)->update(['status' => 'completed']);
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = latestInvoice($this->company);
    $this->post(route('payments.store', $invoice), ['amount' => '50.00', 'method' => 'cash']);

    $this->post(route('invoices.void', $invoice), ['reason' => 'Wrong customer'])->assertSessionHasErrors('invoice');
    expect(latestInvoice($this->company)->status)->toBe(InvoiceStatus::PartiallyPaid);

    $payment = inCompany($this->company, fn () => $invoice->payments()->sole());
    $this->post(route('payments.void', $payment));
    $this->post(route('invoices.void', $invoice), ['reason' => 'Wrong customer'])->assertRedirect();

    expect(latestInvoice($this->company))
        ->status->toBe(InvoiceStatus::Void)
        ->balance->toBe(0)
        ->void_reason->toBe('Wrong customer')
        ->voided_by->toBe($this->owner->id)
        ->and(jobStatus($this->job))->toBe(JobStatus::Completed)
        ->and(inCompany($this->company, fn () => AuditLog::where('action', 'invoice.voided')->count()))->toBe(1);

    // A void invoice is final.
    $this->put(route('invoices.update', $invoice), documentPayload())->assertForbidden();
    $this->post(route('payments.store', $invoice), ['amount' => '1.00', 'method' => 'cash'])->assertForbidden();
});

test('voiding an invoice made from an estimate lets the estimate be invoiced again', function () {
    $this->post(route('estimates.store', $this->job), documentPayload());
    $estimate = inCompany($this->company, fn () => Estimate::sole());
    $this->put(route('estimates.decide', $estimate), ['approved' => true]);
    $this->post(route('estimates.convert', $estimate));

    $this->post(route('invoices.void', latestInvoice($this->company)));

    expect(inCompany($this->company, fn () => Estimate::sole()->status))->toBe(EstimateStatus::Approved);
    $this->post(route('estimates.convert', $estimate))->assertRedirect();
    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(2);
});

test('a job with invoices cannot be deleted', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());

    $this->delete(route('jobs.destroy', $this->job))->assertRedirect(route('jobs.show', $this->job));

    expect(ServiceJob::withoutCompanyScope()->find($this->job->id))->not->toBeNull();
});

test('the invoice list shows outstanding invoices by default', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $this->post(route('invoices.store', $this->job), documentPayload(['items' => [['description' => 'Fee', 'quantity' => '1', 'unit_price' => '50']]]));
    $paid = latestInvoice($this->company);
    $this->post(route('payments.store', $paid), ['amount' => '50.00', 'method' => 'cash']);

    $this->get(route('invoices.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('invoices/index')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.number', 'INV-1042')
            ->where('outstandingTotal', 28050));

    $this->get(route('invoices.index', ['status' => 'paid']))
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', 'INV-1043'));

    $this->get(route('invoices.index', ['status' => 'all', 'search' => 'INV-1043']))
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
});

test('an office user limited to a brand only sees that brand\'s invoices', function () {
    $this->post(route('invoices.store', $this->job), documentPayload());
    $invoice = latestInvoice($this->company);

    $admin = memberOf($this->company, UserRole::Admin);
    $otherBrand = Brand::factory()->create(['company_id' => $this->company->id]);
    inCompany($this->company, fn () => $admin->brands()->attach($otherBrand->id, ['company_id' => $this->company->id]));

    $this->actingAs($admin)->get(route('invoices.index', ['status' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 0));
    $this->actingAs($admin)->get(route('invoices.show', $invoice))->assertForbidden();
});
