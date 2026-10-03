<?php

use App\Enums\LineKind;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Models\SupplierReceipt;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\JobProfit;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Parts and materials with cost on lines, markup, internal lines, warranty per line, price book, job profit.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create([
        'warranty_labor_value' => 30, 'warranty_labor_unit' => 'days',
        'warranty_parts_value' => 90, 'warranty_parts_unit' => 'days',
        'warranty_parts_threshold' => 30000, 'warranty_parts_above_value' => 3, 'warranty_parts_above_unit' => 'months',
        'warranty_terms' => 'Warranty covers the same fault only.',
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->withVisit($this->tech)->create(['brand_id' => $brand->id]);
    $this->pst = inCompany($this->company, fn () => $this->company->taxRates()->create(['name' => 'PST', 'rate' => 7, 'is_active' => true, 'is_recoverable' => false]));
    $this->gst = inCompany($this->company, fn () => $this->company->taxRates()->create(['name' => 'GST', 'rate' => 5, 'is_active' => true, 'is_recoverable' => true]));
    $this->actingAs($this->owner);
});

function costPayload(array $test, array $overrides = []): array
{
    return documentPayload([
        'items' => [
            ['description' => 'Labour', 'quantity' => '1', 'unit_price' => '120.00', 'taxable' => true],
            [
                'description' => 'Drain pump', 'kind' => 'part', 'part_number' => 'WPW10730972', 'supplier' => 'Reliable Parts',
                'quantity' => '1', 'unit_cost' => '40.00', 'unit_price' => '89.00', 'taxable' => true,
                'supplier_taxes' => [['tax_rate_id' => $test['gst'], 'amount' => '2.00'], ['tax_rate_id' => $test['pst'], 'amount' => '2.80']],
            ],
            [
                'description' => 'Control board', 'kind' => 'part', 'quantity' => '1', 'unit_cost' => '250.00', 'unit_price' => '420.00', 'taxable' => true,
            ],
            [
                'description' => 'Wire nuts', 'kind' => 'material', 'unit' => 'pcs', 'quantity' => '4', 'unit_cost' => '0.25',
                'unit_price' => '0.50', 'taxable' => true, 'bill_to_customer' => false,
            ],
        ],
        ...$overrides,
    ]);
}

test('lines carry kind, cost, supplier tax, units, internal flag and the default warranty', function () {
    $this->post(route('invoices.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    [$labour, $pump, $board, $nuts] = $invoice->items->all();

    // The internal line is not billed: 120 + 89 + 420.
    expect($invoice->subtotal)->toBe(62900)
        ->and($pump->kind)->toBe(LineKind::Part)
        ->and($pump->part_number)->toBe('WPW10730972')
        // Cost: 40 + PST 2.80 (not recoverable); GST is recoverable.
        ->and($pump->totalCost())->toBe(4280)
        ->and($nuts->unit)->toBe('pcs')->and($nuts->bill_to_customer)->toBeFalse()->and($nuts->totalCost())->toBe(100)
        // Warranty defaults: labour 30 days, parts 90 days, parts above $300: 3 months; materials like labour.
        ->and([$labour->warranty_value, $labour->warranty_unit])->toBe([30, 'days'])
        ->and([$pump->warranty_value, $pump->warranty_unit])->toBe([90, 'days'])
        ->and([$board->warranty_value, $board->warranty_unit])->toBe([3, 'months'])
        ->and([$nuts->warranty_value, $nuts->warranty_unit])->toBe([30, 'days'])
        // Until the job is closed the warranty runs from the invoice date.
        ->and($pump->warranty_ends_on->toDateString())->toBe('2027-01-12');

    // The customer never sees the internal line; the warranty and terms are printed.
    $print = inCompany($this->company, fn () => DocumentPrint::data($invoice->fresh()));
    expect(collect($print['items'])->pluck('description')->all())->toBe(['Labour', 'Drain pump', 'Control board'])
        ->and($print['items'][1]['warranty'])->toBe('90 days')
        ->and($print['warranty_terms'])->toBe('Warranty covers the same fault only.');
    $html = inCompany($this->company, fn () => view('pdf.document', ['doc' => DocumentPrint::data($invoice->fresh(), embedLogo: true), 'url' => null])->render());
    expect($html)->not->toContain('Wire nuts')->toContain('Warranty: 90 days (until')->toContain('Warranty terms');

    // Staff see costs.
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('document.items.1.unit_cost', 4000)
        ->where('document.items.1.total_cost', 4280)
        ->where('document.cost_total', 4000 + 280 + 25000 + 100));
});

test('costs stay private to their author and another technician edits only customer prices', function () {
    $this->post(route('invoices.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());

    $this->actingAs($this->tech);
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('document.items.1.unit_cost', null)
        ->where('document.items.1.costs_editable', false));
    $this->getJson(route('pricebook.history', ['q' => 'WPW']))->assertOk()->assertJsonPath('history', []);

    // The technician changes the price; the cost stays.
    $items = $invoice->items->map(fn ($item) => [
        'id' => $item->id, 'description' => $item->description, 'kind' => $item->kind->value, 'quantity' => (string) $item->quantity,
        'unit_price' => number_format($item->unit_price / 100, 2, '.', ''), 'taxable' => $item->taxable, 'bill_to_customer' => $item->bill_to_customer,
        'unit_cost' => '1.00',
    ])->all();
    $items[1]['unit_price'] = '95.00';
    $this->put(route('invoices.update', $invoice), documentPayload(['items' => $items]))->assertSessionHasNoErrors();
    $pump = inCompany($this->company, fn () => $invoice->fresh()->items[1]);
    expect($pump->unit_price)->toBe(9500)->and($pump->unit_cost)->toBe(4000);

    $this->company->update(['technicians_see_costs' => true]);
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page->where('document.items.1.unit_cost', null));
});

test('earlier costs of a part show by part number or name, newest first', function () {
    $this->post(route('invoices.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $this->travel(30)->days();
    $this->post(route('estimates.store', $this->job), documentPayload(['items' => [
        ['description' => 'Drain pump', 'kind' => 'part', 'part_number' => 'WPW10730972', 'supplier' => 'Marcone', 'quantity' => '1', 'unit_cost' => '46.50', 'unit_price' => '99.00', 'taxable' => true],
    ]]))->assertSessionHasNoErrors();

    $history = $this->getJson(route('pricebook.history', ['q' => 'wpw1073']))->assertOk()->json('history');

    expect(collect($history)->pluck('unit_cost')->all())->toBe([4650, 4000])
        ->and($history[0]['supplier'])->toBe('Marcone');
});

test('a line can be saved to the price book, and its warranty is then the default', function () {
    $this->postJson(route('pricebook.store'), [
        'kind' => 'service', 'name' => 'Drain unclogging', 'unit_price' => '149.00', 'warranty_value' => 14, 'warranty_unit' => 'days',
    ])->assertCreated();
    $service = inCompany($this->company, fn () => Service::query()->where('name', 'Drain unclogging')->sole());

    $this->post(route('invoices.store', $this->job), documentPayload(['items' => [
        ['description' => 'Drain unclogging', 'service_id' => $service->id, 'quantity' => '1', 'unit_price' => '149.00', 'taxable' => true],
    ]]))->assertSessionHasNoErrors();

    $line = inCompany($this->company, fn () => Invoice::query()->sole()->items->sole());
    expect([$line->warranty_value, $line->warranty_unit])->toBe([14, 'days']);
});

test('client prices are entered directly even when a legacy markup scale exists', function () {
    $this->company->update(['markup_parts' => [['up_to' => null, 'multiplier' => 9]]]);
    $this->post(route('invoices.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $this->get(route('invoices.edit', inCompany($this->company, fn () => Invoice::query()->sole())))->assertInertia(fn (Assert $page) => $page
        ->missing('lineSetup.markup')->where('document.items.1.unit_price', 8900)->where('document.items.1.unit_cost', 4000));
});

test('closing the job sets the warranty dates; the summary changes them, "apply to all" included', function () {
    $this->post(route('invoices.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $this->travel(5)->days();

    $this->post(route('jobs.close', $this->job), ['outcome' => 'repaired'])->assertRedirect(route('jobs.show', ['job' => $this->job->id, 'warranty' => 1]));
    $invoice = inCompany($this->company, fn () => Invoice::query()->with('items')->sole());
    expect($invoice->items[1]->warranty_ends_on->toDateString())->toBe('2027-01-17');

    $this->get(route('jobs.show', ['job' => $this->job->id, 'warranty' => 1]))->assertInertia(fn (Assert $page) => $page
        ->where('openWarranty', true)
        ->has('warrantyLines', 4));

    $this->put(route('jobs.warranties.update', $this->job), ['items' => $invoice->items->map(fn ($i) => [
        'id' => $i->id, 'warranty_value' => 6, 'warranty_unit' => 'months',
    ])->all()])->assertRedirect();
    expect(inCompany($this->company, fn () => $invoice->fresh()->items->pluck('warranty_ends_on')->map->toDateString()->unique()->all()))->toBe(['2027-04-19']);
});

test('converting an estimate keeps costs, internal lines and warranties', function () {
    $this->post(route('estimates.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $estimate = inCompany($this->company, fn () => Estimate::sole());

    $this->post(route('estimates.convert', $estimate))->assertRedirect();

    $items = inCompany($this->company, fn () => Invoice::query()->sole()->items);
    expect($items[1]->unit_cost)->toBe(4000)
        ->and($items[1]->supplier)->toBe('Reliable Parts')
        ->and($items[3]->bill_to_customer)->toBeFalse()
        ->and($items[2]->warranty_unit)->toBe('months');
});

test('the job profit takes revenue without tax, all costs and processor fees', function () {
    $this->post(route('invoices.store', $this->job), costPayload(['gst' => $this->gst->id, 'pst' => $this->pst->id]))->assertSessionHasNoErrors();
    $this->post(route('jobs.costs.store', $this->job), ['kind' => 'material', 'description' => 'Hose', 'quantity' => '2', 'unit' => 'ft', 'unit_cost' => '3.00'])->assertSessionHasNoErrors();
    inCompany($this->company, function () {
        $invoice = Invoice::query()->sole();
        $payment = new Payment(['amount' => 1000, 'method' => 'online', 'received_at' => now(), 'provider' => 'square', 'provider_payment_id' => 'P1', 'processing_fee' => 59]);
        $payment->invoice_id = $invoice->id;
        $payment->currency = $invoice->currency;
        $payment->processing_fee = 59;
        $payment->save();
    });

    $profit = inCompany($this->company, fn () => JobProfit::for($this->job->fresh()));

    expect($profit['revenue'])->toBe(62900)
        ->and($profit['cost'])->toBe(4280 + 25000 + 100 + 600)
        ->and($profit['fees'])->toBe(59)
        ->and($profit['profit'])->toBe(62900 - 29980 - 59);

    $this->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('costs.profit.profit', 62900 - 29980 - 59));
    $this->actingAs($this->tech)->get(route('jobs.show', $this->job))->assertInertia(fn (Assert $page) => $page->where('costs', null));
});

test('supplier receipts are internal, can cover several jobs and are not reachable from another company', function () {
    Storage::fake(PrivateMedia::diskName());
    $other = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))->create(['brand_id' => $this->job->brand_id]);

    $this->post(route('jobs.receipts.store', $this->job), [
        'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        'supplier' => 'Reliable Parts', 'amount' => '86.80',
    ])->assertSessionHasNoErrors();
    $receipt = inCompany($this->company, fn () => SupplierReceipt::sole());
    $this->post(route('receipts.link', $receipt), ['job_number' => $other->number])->assertSessionHasNoErrors();

    expect(inCompany($this->company, fn () => $receipt->jobs()->pluck('service_jobs.id')->sort()->values()->all()))->toBe(collect([$this->job->id, $other->id])->sort()->values()->all());
    $this->get(route('receipts.show', $receipt))->assertOk();

    // Deleting a job keeps its receipts.
    $this->delete(route('jobs.destroy', $other));
    expect(inCompany($this->company, fn () => $receipt->jobs()->count()))->toBe(2);

    $this->actingAs($this->tech)->get(route('receipts.show', $receipt))->assertForbidden();

    app(CurrentCompany::class)->forget();
    $this->actingAs(memberOf(Company::factory()->create()));
    $this->get(route('receipts.show', $receipt))->assertNotFound();
});
