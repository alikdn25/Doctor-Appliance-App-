<?php

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($property)->withVisit($this->tech)->create(['brand_id' => $brand->id]);
    inCompany($this->company, fn () => GoogleProfile::create(['label' => 'Burnaby', 'review_url' => 'https://g.page/r/test']));

    $this->actingAs($this->owner)->post(route('invoices.store', $this->job), documentPayload());
    $this->invoice = inCompany($this->company, fn () => Invoice::sole());
});

test('the job page offers "Mark as paid" for the balance with cash, card, e-transfer and crypto', function () {
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('payable.0.id', $this->invoice->id)
        ->where('payable.0.balance', $this->invoice->balance)
        ->where('paymentMethods', fn ($methods) => collect($methods)->pluck('label')->take(5)->all() === ['Cash', 'Card', 'E-transfer', 'Crypto', 'Other']));

    // Elsewhere the transfer keeps its generic name.
    $this->company->update(['country' => 'US']);
    expect(inCompany($this->company, fn () => PaymentMethod::BankTransfer->label()))->toBe('Bank transfer');
});

test('a partial payment leaves the balance on the invoice and asks nothing', function () {
    $this->post(route('payments.store', $this->invoice), ['amount' => '100', 'method' => 'cash'])
        ->assertSessionMissing('inertia.flash_data.paid_in_full');

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('payable.0.balance', 28050 - 10000));
    $this->get(route('invoices.show', $this->invoice))->assertInertia(fn (Assert $page) => $page
        ->where('document.balance', 18050));
});

test('paying in full asks for the review request and offers to complete the job in one tap', function () {
    $response = $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'cash']);
    $response->assertRedirect();

    $paid = inertiaFlash('paid_in_full');
    expect($paid['number'])->toBe($this->invoice->number)
        ->and($paid['complete'])->toBe(['close_url' => route('jobs.close', $this->job)])
        ->and($paid['review']['profiles'][0]['label'])->toBe('Burnaby');

    $this->post($paid['complete']['close_url'], ['outcome' => 'repaired'])->assertRedirect();
    expect(ServiceJob::withoutCompanyScope()->find($this->job->id)->status)->toBe(JobStatus::Paid);

    // Nothing left to pay: no button.
    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('payable', []));
});

test('with a visit under way the paid job leads to Finish visit instead', function () {
    $visit = inCompany($this->company, fn () => $this->job->visits()->first());
    inCompany($this->company, fn () => $visit->forceFill(['status' => VisitStatus::InProgress, 'started_at' => now()])->save());

    $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'crypto']);

    expect(inertiaFlash('paid_in_full')['complete'])->toBe(['finish_url' => route('visits.finish-screen', $visit)]);
});

test('a job already closed is not offered again', function () {
    $this->post(route('jobs.close', $this->job), ['outcome' => 'repaired']);
    $this->post(route('payments.store', $this->invoice), ['amount' => '280.50', 'method' => 'cash']);

    expect(inertiaFlash('paid_in_full')['complete'])->toBeNull();
});

function inertiaFlash(string $key): mixed
{
    return session('inertia.flash_data')[$key] ?? null;
}
