<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['default_payment_terms' => 'net_7']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create(['type' => 'strata', 'company_name' => 'Harbour Strata']);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    $this->today = CarbonImmutable::now('America/Vancouver')->startOfDay();
    $this->actingAs($this->owner);
});

test('the company default terms set the due date of new invoices', function () {
    $this->get(route('invoices.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('defaultDueOn', $this->today->addDays(7)->toDateString())
        ->where('paymentTerms', 'Net 7'));

    $this->post(route('invoices.store', $this->job), documentPayload(['due_on' => null]))->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => Invoice::sole())->due_on->toDateString())
        ->toBe($this->today->addDays(7)->toDateString());
});

test('a customer can have its own terms, e.g. Net 30 for a strata', function () {
    $this->put(route('customers.update', $this->customer), [
        'type' => 'strata', 'company_name' => 'Harbour Strata', 'payment_terms' => 'net_30', 'phones' => [], 'emails' => [],
    ])->assertSessionHasNoErrors();

    expect($this->customer->fresh()->payment_terms->value)->toBe('net_30');

    $this->get(route('invoices.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('defaultDueOn', $this->today->addDays(30)->toDateString())
        ->where('paymentTerms', 'Net 30'));

    $this->post(route('invoices.store', $this->job), documentPayload(['due_on' => null]));
    expect(inCompany($this->company, fn () => Invoice::sole())->due_on->toDateString())->toBe($this->today->addDays(30)->toDateString());

    // Back to the company default.
    $this->put(route('customers.update', $this->customer), [
        'type' => 'strata', 'company_name' => 'Harbour Strata', 'payment_terms' => null, 'phones' => [], 'emails' => [],
    ]);
    expect($this->customer->fresh()->payment_terms)->toBeNull();
});

test('a due date typed on the invoice wins over the terms', function () {
    $this->post(route('invoices.store', $this->job), documentPayload(['due_on' => $this->today->addDays(3)->toDateString()]));

    expect(inCompany($this->company, fn () => Invoice::sole())->due_on->toDateString())->toBe($this->today->addDays(3)->toDateString());
});

test('an invoice made from an estimate is due by the customer terms', function () {
    $this->customer->update(['payment_terms' => 'net_15']);
    $this->post(route('estimates.store', $this->job), documentPayload());
    $estimate = inCompany($this->company, fn () => Estimate::sole());

    $this->post(route('estimates.convert', $estimate))->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => Invoice::sole());
    expect($invoice->due_on->toDateString())->toBe($invoice->issued_on->addDays(15)->toDateString());
});

test('the company default terms are a setting', function () {
    $this->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('company.default_payment_terms', 'net_7')
        ->has('paymentTerms', 4));
});
