<?php

use App\Enums\EstimateStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * UX-REDESIGN stage 9: invoices list (§10.1), new invoice (§10.3) and invoice from an approved estimate (§21.2).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2030-03-10 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->job = ServiceJob::factory()
        ->for(Property::factory()->for(Customer::factory()->for($this->company)->create(['first_name' => 'Nora'])))
        ->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);
});

function moneyInvoice(array $overrides = []): Invoice
{
    test()->post(route('invoices.store', test()->job), documentPayload($overrides))->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => Invoice::query()->latest('id')->first());
}

function moneyEstimate(): Estimate
{
    test()->post(route('estimates.store', test()->job), documentPayload(['items' => [
        ['description' => 'Replace drain pump', 'quantity' => '1', 'unit_price' => '210.00', 'taxable' => true, 'kind' => 'part', 'part_number' => 'WPX123'],
        ['description' => 'Labor', 'quantity' => '1.5', 'unit_price' => '120.00', 'taxable' => true],
    ]]))->assertSessionHasNoErrors();

    return inCompany(test()->company, fn () => Estimate::query()->latest('id')->first());
}

test('the list shows outstanding and overdue totals and how many days each invoice has left', function () {
    $overdue = moneyInvoice(['due_on' => '2030-03-12']);
    $due = moneyInvoice(['due_on' => '2030-03-15']);
    $this->travelTo(CarbonImmutable::parse('2030-03-14 12:00', 'America/Vancouver'));

    $this->get(route('invoices.index'))->assertInertia(fn (Assert $page) => $page
        ->component('invoices/index')
        ->where('filters.status', 'outstanding')
        ->where('outstandingTotals.0.amount', $overdue->total + $due->total)
        ->where('overdueTotals.0.amount', $overdue->total)
        ->where('invoices.data', fn ($rows) => collect($rows)->pluck('due_in_days', 'number')->all() === [
            $due->number => 1,
            $overdue->number => -2,
        ]));

    // Paid: no days left, and it shows under the Paid chip.
    $this->post(route('payments.store', $due), ['amount' => number_format($due->total / 100, 2, '.', ''), 'method' => 'cash'])->assertSessionHasNoErrors();
    $this->get(route('invoices.index', ['status' => 'paid']))->assertInertia(fn (Assert $page) => $page
        ->has('invoices.data', 1)
        ->where('invoices.data.0.number', $due->number)
        ->where('invoices.data.0.due_in_days', null));
});

test('another company\'s invoices never count in the totals', function () {
    $other = Company::factory()->create();
    $foreignOwner = memberOf($other, UserRole::Owner);
    moneyInvoice();
    app(CurrentCompany::class)->forget();

    $this->actingAs($foreignOwner)->get(route('invoices.index'))->assertInertia(fn (Assert $page) => $page
        ->where('outstandingTotals', [])
        ->where('overdueTotals', [])
        ->has('invoices.data', 0));
});

test('a new invoice on a job with an approved estimate starts with its lines', function () {
    $estimate = moneyEstimate();

    // Not approved yet: an empty invoice, but the estimate is offered under Add item.
    $this->get(route('invoices.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('fromEstimateId', null)
        ->has('estimates', 1)
        ->has('estimates.0.items', 2));

    $this->put(route('estimates.decide', $estimate), ['approved' => true])->assertSessionHasNoErrors();

    $this->get(route('invoices.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('fromEstimateId', $estimate->id)
        ->where('estimates.0.items.0.description', 'Replace drain pump')
        ->where('estimates.0.items.0.part_number', 'WPX123'));

    // Saved with one line changed: the estimate is invoiced and linked.
    $this->post(route('invoices.store', $this->job), documentPayload([
        'estimate_id' => $estimate->id,
        'items' => [
            ['description' => 'Replace drain pump', 'quantity' => '1', 'unit_price' => '199.00', 'taxable' => true, 'kind' => 'part'],
            ['description' => 'Labor', 'quantity' => '1.5', 'unit_price' => '120.00', 'taxable' => true],
        ],
    ]))->assertSessionHasNoErrors();

    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());
    expect($invoice->estimate_id)->toBe($estimate->id)
        ->and($invoice->items()->count())->toBe(2)
        ->and($estimate->fresh()->status)->toBe(EstimateStatus::Invoiced);

    // Once invoiced it is not offered again.
    $this->get(route('invoices.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->where('fromEstimateId', null));
});

test('an invoice cannot claim an estimate that is not approved or is from another job', function () {
    $estimate = moneyEstimate();

    $this->post(route('invoices.store', $this->job), documentPayload(['estimate_id' => $estimate->id]))
        ->assertSessionHasErrors('estimate_id');

    $otherJob = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))->create(['brand_id' => $this->brand->id]);
    $this->put(route('estimates.decide', $estimate), ['approved' => true]);

    // Another job's estimate is ignored: the invoice is made, the estimate stays approved.
    $this->post(route('invoices.store', $otherJob), documentPayload(['estimate_id' => $estimate->id]))->assertSessionHasNoErrors();

    expect($estimate->fresh()->status)->toBe(EstimateStatus::Approved)
        ->and(inCompany($this->company, fn () => Invoice::query()->sole()->estimate_id))->toBeNull();
});
