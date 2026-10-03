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
use App\Models\TaxRate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['estimate_prefix' => 'EST-', 'estimate_next_number' => 100]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->otherTech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->property = Property::factory()->for(Customer::factory()->for($this->company))->create();
    $this->job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $this->gst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true, 'sort_order' => 1]);
    $this->pst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 2]);

    $this->actingAs($this->owner);
});

function estimateOf(Company $company): Estimate
{
    return inCompany($company, fn () => Estimate::with('items')->latest('id')->firstOrFail());
}

test('the new estimate form offers the active taxes with defaults ticked', function () {
    TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'Old', 'is_active' => false]);

    $this->get(route('estimates.create', $this->job))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/form')
            ->where('kind', 'estimate')
            ->where('job.id', $this->job->id)
            ->has('taxRates', 2)
            ->where('taxRates.0.name', 'GST')
            ->where('taxRates.0.rate', '5')
            ->where('taxRates.0.is_default', true));
});

test('an estimate is created from a job with lines, discount and taxes', function () {
    $this->post(route('estimates.store', $this->job), documentPayload([
        'valid_until' => now()->addDays(30)->toDateString(),
        'tax_rate_ids' => [$this->gst->id, $this->pst->id],
        'discount_type' => 'amount',
        'discount_value' => '20.50',
        'notes' => '90-day warranty on parts.',
    ]))->assertRedirect();

    $estimate = estimateOf($this->company);

    expect($estimate)
        ->number->toBe('EST-100')
        ->status->toBe(EstimateStatus::Draft)
        ->service_job_id->toBe($this->job->id)
        ->customer_id->toBe($this->job->customer_id)
        ->brand_id->toBe($this->brand->id)
        ->created_by->toBe($this->owner->id)
        ->subtotal->toBe(28050)
        ->discount_total->toBe(2050)
        ->tax_total->toBe(3120) // 12% of 260.00
        ->total->toBe(29120)
        ->notes->toBe('90-day warranty on parts.')
        ->and($estimate->items->pluck('total')->all())->toBe([9500, 18550])
        ->and($estimate->taxes)->toEqual([
            ['tax_rate_id' => $this->gst->id, 'name' => 'GST', 'rate' => '5', 'compound' => false, 'amount' => 1300],
            ['tax_rate_id' => $this->pst->id, 'name' => 'PST', 'rate' => '7', 'compound' => false, 'amount' => 1820],
        ])
        ->and($this->company->fresh()->estimate_next_number)->toBe(101);

    $this->get(route('estimates.show', $estimate))
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/show')
            ->where('document.number', 'EST-100')
            ->where('document.total', 29120)
            ->has('document.items', 2)
            ->where('can.convert', true));
});

test('an assigned technician creates an estimate on site, others cannot', function () {
    $this->actingAs($this->tech)->post(route('estimates.store', $this->job), documentPayload())->assertRedirect();

    $this->actingAs($this->otherTech)->get(route('estimates.create', $this->job))->assertForbidden();
    $this->actingAs($this->otherTech)->post(route('estimates.store', $this->job), documentPayload())->assertForbidden();
    $this->actingAs($this->otherTech)->get(route('estimates.show', estimateOf($this->company)))->assertForbidden();

    expect(inCompany($this->company, fn () => Estimate::count()))->toBe(1);
});

test('an estimate needs lines with a description, quantity and price', function () {
    $this->post(route('estimates.store', $this->job), documentPayload(['items' => []]))->assertSessionHasErrors('items');

    $this->post(route('estimates.store', $this->job), documentPayload([
        'items' => [['description' => '', 'quantity' => '0', 'unit_price' => '12.345']],
        'discount_type' => 'percent',
        'discount_value' => '150',
    ]))->assertSessionHasErrors(['items.0.description', 'items.0.quantity', 'items.0.unit_price', 'discount_value']);

    $this->post(route('estimates.store', $this->job), documentPayload([
        'items' => [['description' => 'Credit', 'quantity' => '1', 'unit_price' => '-10.00']],
    ]))->assertSessionHasErrors('items');

    expect(inCompany($this->company, fn () => Estimate::count()))->toBe(0);
});

test('editing an estimate keeps the tax rate it was made with', function () {
    $this->post(route('estimates.store', $this->job), documentPayload(['tax_rate_ids' => [$this->gst->id]]));
    $estimate = estimateOf($this->company);

    $this->gst->update(['rate' => 6]);

    $this->put(route('estimates.update', $estimate), documentPayload([
        'tax_rate_ids' => [$this->gst->id, $this->pst->id],
        'items' => [['description' => 'Repair', 'quantity' => '2', 'unit_price' => '100', 'taxable' => true]],
    ]))->assertRedirect(route('estimates.show', $estimate));

    $estimate = estimateOf($this->company);
    expect($estimate->items)->toHaveCount(1)
        ->and(collect($estimate->taxes)->pluck('rate', 'name')->all())->toBe(['GST' => '5', 'PST' => '7'])
        ->and($estimate->total)->toBe(22400);
});

test('the customer\'s decision is recorded on the estimate', function () {
    $this->post(route('estimates.store', $this->job), documentPayload());
    $estimate = estimateOf($this->company);

    $this->put(route('estimates.decide', $estimate), ['approved' => true])->assertRedirect();
    expect(estimateOf($this->company))->status->toBe(EstimateStatus::Approved)->approved_at->not->toBeNull();

    $this->put(route('estimates.decide', $estimate), ['approved' => false]);
    expect(estimateOf($this->company))->status->toBe(EstimateStatus::Declined)->approved_at->toBeNull()->declined_at->not->toBeNull();
});

test('an estimate turns into an invoice with the same lines, discount and taxes', function () {
    $this->post(route('estimates.store', $this->job), documentPayload([
        'tax_rate_ids' => [$this->gst->id],
        'discount_type' => 'percent',
        'discount_value' => '10',
        'notes' => 'Thanks!',
    ]));
    $estimate = estimateOf($this->company);
    $this->gst->update(['rate' => 6]);

    $this->post(route('estimates.convert', $estimate))->assertRedirect();

    $invoice = inCompany($this->company, fn () => Invoice::with('items')->sole());
    $estimate = estimateOf($this->company);

    expect($invoice)
        ->estimate_id->toBe($estimate->id)
        ->service_job_id->toBe($this->job->id)
        ->total->toBe($estimate->total)
        ->discount_total->toBe($estimate->discount_total)
        ->taxes->toEqual($estimate->taxes)
        ->notes->toBe('Thanks!')
        ->balance->toBe($estimate->total)
        ->and($invoice->items->map->only(['description', 'quantity', 'unit_price', 'taxable', 'total'])->all())
        ->toBe($estimate->items->map->only(['description', 'quantity', 'unit_price', 'taxable', 'total'])->all())
        ->and($estimate->status)->toBe(EstimateStatus::Invoiced);

    // Once invoiced, the estimate is final.
    $this->post(route('estimates.convert', $estimate))->assertForbidden();
    $this->put(route('estimates.update', $estimate), documentPayload())->assertForbidden();
    $this->delete(route('estimates.destroy', $estimate))->assertForbidden();
    expect(inCompany($this->company, fn () => Invoice::count()))->toBe(1);
});

test('a draft estimate can be deleted', function () {
    $this->post(route('estimates.store', $this->job), documentPayload());

    $this->delete(route('estimates.destroy', estimateOf($this->company)))->assertRedirect(route('jobs.show', $this->job));

    expect(inCompany($this->company, fn () => Estimate::count()))->toBe(0);
});

test('the job page lists its estimates and invoices', function () {
    $this->post(route('estimates.store', $this->job), documentPayload());
    $this->post(route('estimates.convert', estimateOf($this->company)));

    $this->get(route('jobs.show', $this->job))
        ->assertInertia(fn (Assert $page) => $page
            ->has('job.estimates', 1)
            ->where('job.estimates.0.status', 'invoiced')
            ->has('job.invoices', 1)
            ->where('job.invoices.0.balance', 28050));
});

test('per item tax choices persist through edits and conversion using historical names and rates', function () {
    $items = [
        ['description' => 'Labor', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => [$this->gst->id]],
        ['description' => 'Part', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => [$this->gst->id, $this->pst->id]],
        ['description' => 'Exempt', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => []],
    ];
    $payload = documentPayload(['tax_rate_ids' => [$this->gst->id, $this->pst->id], 'items' => $items]);
    $this->actingAs($this->tech)->post(route('estimates.store', $this->job), $payload)->assertSessionHasNoErrors();
    $estimate = estimateOf($this->company);
    expect($estimate->total)->toBe(31700)->and($estimate->items->pluck('tax_rate_ids')->all())->toBe([[$this->gst->id], [$this->gst->id, $this->pst->id], []]);
    $this->gst->update(['name' => 'Renamed', 'rate' => 20, 'is_active' => false]);
    // Older clients omitting the new field keep the selection of their existing lines.
    $payload['items'] = $estimate->items->map(fn ($item) => $item->only(['id', 'description', 'quantity', 'taxable']) + ['unit_price' => '100'])->all();
    $this->put(route('estimates.update', $estimate), $payload)->assertSessionHasNoErrors();
    $estimate = estimateOf($this->company);
    expect($estimate->total)->toBe(31700)->and($estimate->taxes[0]['name'])->toBe('GST')->and($estimate->taxes[0]['rate'])->toBe('5');
    $this->get(route('estimates.show', $estimate))->assertInertia(fn (Assert $page) => $page
        ->where('document.items.0.tax_names', ['GST'])->where('document.items.1.tax_names', ['GST', 'PST'])->where('document.items.2.tax_names', []));
    $this->post(route('estimates.convert', $estimate))->assertRedirect();
    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    expect($invoice->total)->toBe(31700)->and($invoice->taxes)->toBe($estimate->taxes)
        ->and($invoice->items->pluck('tax_rate_ids')->all())->toBe($estimate->items->pluck('tax_rate_ids')->all());
});

test('new documents reject disabled foreign and document-disabled item taxes', function () {
    $disabled = TaxRate::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
    $foreign = TaxRate::factory()->create();
    foreach ([$disabled->id, $foreign->id] as $id) {
        $this->post(route('estimates.store', $this->job), documentPayload(['tax_rate_ids' => [$id]]))->assertSessionHasErrors('tax_rate_ids.0');
    }
    $this->post(route('invoices.store', $this->job), documentPayload([
        'tax_rate_ids' => [$this->gst->id],
        'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => [$this->pst->id]]],
    ]))->assertSessionHasErrors('items.0.tax_rate_ids.0');
});

test('documents accept more than five company taxes on an item and its supplier costs', function () {
    $rates = TaxRate::factory()->count(7)->create(['company_id' => $this->company->id, 'rate' => 1, 'is_compound' => false]);
    $this->post(route('invoices.store', $this->job), documentPayload([
        'tax_rate_ids' => $rates->modelKeys(),
        'items' => [['description' => 'Part', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => $rates->modelKeys(),
            'supplier_taxes' => $rates->map(fn ($rate) => ['tax_rate_id' => $rate->id, 'amount' => '1'])->all()]],
    ]))->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    expect($invoice->taxes)->toHaveCount(7)->and($invoice->total)->toBe(10700)->and($invoice->items->first()->supplier_taxes)->toHaveCount(7);
});

test('saved compound ordering and edit form rates survive changed company settings', function () {
    $this->pst->update(['is_compound' => true]);
    $payload = documentPayload(['tax_rate_ids' => [$this->gst->id, $this->pst->id], 'items' => [
        ['description' => 'Repair', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => [$this->gst->id, $this->pst->id]],
        ['description' => 'Regional only', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => [$this->pst->id]],
    ]]);
    $this->post(route('estimates.store', $this->job), $payload)->assertSessionHasNoErrors();
    $estimate = estimateOf($this->company);
    expect($estimate->total)->toBe(21935);
    $this->gst->update(['is_compound' => true, 'name' => 'Changed federal', 'rate' => 20]);
    $this->pst->update(['is_compound' => false, 'name' => 'Changed regional', 'rate' => 15]);
    $this->get(route('estimates.edit', $estimate))->assertInertia(fn (Assert $page) => $page
        ->where('taxRates.0.id', $this->gst->id)->where('taxRates.0.name', 'GST')->where('taxRates.0.rate', '5')
        ->where('taxRates.0.is_compound', false)->where('taxRates.1.is_compound', true));
    $this->put(route('estimates.update', $estimate), $payload)->assertSessionHasNoErrors();
    expect(estimateOf($this->company)->total)->toBe(21935)->and(estimateOf($this->company)->taxes)->toBe($estimate->taxes);
});
