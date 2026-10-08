<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->gst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true]);
    $this->pst = TaxRate::factory()->create([
        'company_id' => $this->company->id, 'name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 1,
        'applies_to' => ['part', 'material'],
    ]);
    $property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($property)->create();

    $this->actingAs($this->owner);
});

function kindPayload(array $items): array
{
    return documentPayload(['tax_rate_ids' => [test()->gst->id, test()->pst->id], 'items' => $items]);
}

test('the line types a tax is charged on are a tax setting', function () {
    $this->post(route('taxes.store'), ['name' => 'Sales tax', 'rate' => 6, 'applies_to' => ['part', 'material']])->assertRedirect();
    $this->put(route('taxes.update', $this->gst), ['name' => 'GST', 'rate' => 5, 'is_active' => true, 'applies_to' => ['service', 'part', 'material']])->assertRedirect();

    $rates = inCompany($this->company, fn () => TaxRate::orderBy('name')->get()->keyBy('name'));

    // Every type ticked is stored as "all types", the same as rates made before the setting existed.
    expect($rates['Sales tax']->applies_to)->toBe(['part', 'material'])
        ->and($rates['GST']->applies_to)->toBeNull();

    $this->post(route('taxes.store'), ['name' => 'X', 'rate' => 1, 'applies_to' => ['labor']])->assertSessionHasErrors('applies_to.0');

    $this->get(route('taxes.index'))->assertInertia(fn (Assert $page) => $page
        ->where('taxRates', fn ($rates) => collect($rates)->firstWhere('name', 'PST')['applies_to'] === ['part', 'material']));
});

test('new lines get the taxes of their type: PST on parts and materials, not on labor', function () {
    $this->post(route('invoices.store', $this->job), kindPayload([
        ['description' => 'Labor', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'kind' => 'service'],
        ['description' => 'Drain pump', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'kind' => 'part'],
        ['description' => 'Hose clamp', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'kind' => 'material'],
    ]))->assertRedirect();

    $invoice = inCompany($this->company, fn () => Invoice::with('items')->latest('id')->firstOrFail());
    $taxes = collect($invoice->taxes)->keyBy('name');

    expect($invoice->items->pluck('tax_rate_ids')->all())->toBe([
        [$this->gst->id], [$this->gst->id, $this->pst->id], [$this->gst->id, $this->pst->id],
    ])
        ->and($taxes['GST']['amount'])->toBe(1500)
        ->and($taxes['PST']['amount'])->toBe(1400)
        ->and($invoice->total)->toBe(32900);
});

test('PST can be ticked on a single labor line', function () {
    $this->post(route('invoices.store', $this->job), kindPayload([
        ['description' => 'Labor', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'kind' => 'service', 'tax_rate_ids' => [$this->gst->id, $this->pst->id]],
        ['description' => 'Install', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'kind' => 'service'],
    ]))->assertRedirect();

    $invoice = inCompany($this->company, fn () => Invoice::latest('id')->firstOrFail());

    expect(collect($invoice->taxes)->firstWhere('name', 'PST')['amount'])->toBe(700)
        ->and($invoice->total)->toBe(21700);
});

test('rates without line types keep applying the document taxes to every line', function () {
    $this->pst->update(['applies_to' => null]);

    $this->post(route('invoices.store', $this->job), kindPayload([
        ['description' => 'Labor', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'kind' => 'service'],
    ]))->assertRedirect();

    $invoice = inCompany($this->company, fn () => Invoice::with('items')->latest('id')->firstOrFail());

    expect($invoice->items->first()->tax_rate_ids)->toBeNull()
        ->and($invoice->total)->toBe(11200);
});

test('the invoice form receives the line types of each tax', function () {
    $this->get(route('invoices.create', $this->job))->assertInertia(fn (Assert $page) => $page
        ->component('billing/form')
        ->where('taxRates.0.applies_to', ['service', 'part', 'material'])
        ->where('taxRates.1.applies_to', ['part', 'material']));
});
