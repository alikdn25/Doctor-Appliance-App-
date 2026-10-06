<?php

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\MoneyInput;
use App\Support\Locale\AddressFormatter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->job = ServiceJob::factory()
        ->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->withVisit($this->owner)
        ->create(['brand_id' => $this->brand->id]);

    $this->actingAs($this->owner);
});

test('typed numbers are read with a comma as the decimal mark or thousands separator', function (string $typed, string $read) {
    expect(MoneyInput::clean($typed))->toBe($read);
})->with([
    ['150,00', '150.00'],
    ['150,5', '150.5'],
    ['50,5', '50.5'],
    ['1,500', '1500'],
    ['1,500.50', '1500.50'],
    ['1.500,50', '1500.50'],
    ['1,234,567', '1234567'],
    ['CA$ 150.50', '150.50'],
    ['.5', '0.5'],
    ['-12,5', '-12.5'],
    ['150.5.5', '150.5.5'],
    ['15,5,5', '15,5,5'],
]);

test('a price typed with a comma is saved as cents, not thousands', function () {
    $this->post(route('invoices.store', $this->job), documentPayload(['items' => [
        ['description' => 'Repair', 'quantity' => '1,5', 'unit_price' => '150,00', 'taxable' => true],
    ]]))->assertSessionHasNoErrors();

    $item = inCompany($this->company, fn () => Invoice::query()->sole()->items()->sole());

    expect($item->unit_price)->toBe(15000)
        ->and((float) $item->quantity)->toBe(1.5)
        ->and($item->total)->toBe(22500);
});

test('a number with two decimal points is rejected instead of being cut', function () {
    $this->post(route('invoices.store', $this->job), documentPayload(['items' => [
        ['description' => 'Repair', 'quantity' => '1', 'unit_price' => '150.5.5', 'taxable' => true],
    ]]))->assertSessionHasErrors('items.0.unit_price');

    expect(inCompany($this->company, fn () => Invoice::query()->count()))->toBe(0);
});

test('a service line keeps no purchase price typed while it was a part', function () {
    $this->post(route('invoices.store', $this->job), documentPayload(['items' => [
        ['description' => 'Washer repair', 'kind' => 'service', 'quantity' => '1', 'unit_price' => '120', 'unit_cost' => '40', 'supplier' => 'Marcone'],
        ['description' => 'Drain pump', 'kind' => 'part', 'quantity' => '1', 'unit_price' => '89.50', 'unit_cost' => '40'],
    ]]))->assertSessionHasNoErrors();

    $items = inCompany($this->company, fn () => Invoice::query()->sole()->items()->orderBy('id')->get());

    expect($items[0]->unit_cost)->toBeNull()
        ->and($items[0]->supplier)->toBeNull()
        ->and($items[0]->cost_owner_id)->toBeNull()
        ->and($items[1]->unit_cost)->toBe(4000);
});

test('an optional estimate line the customer has not picked is left out of the purchase total', function () {
    $this->post(route('estimates.store', $this->job), documentPayload(['items' => [
        ['description' => 'Door gasket', 'kind' => 'part', 'quantity' => '1', 'unit_price' => '60', 'unit_cost' => '25'],
        ['description' => 'Drain pump', 'kind' => 'part', 'quantity' => '1', 'unit_price' => '89.50', 'unit_cost' => '40', 'optional' => true],
    ]]))->assertSessionHasNoErrors();

    $estimate = inCompany($this->company, fn () => Estimate::query()->sole());

    $this->get(route('estimates.show', $estimate))
        ->assertInertia(fn (Assert $page) => $page->where('document.cost_total', 2500));
});

test('an unpaid invoice past its due date is overdue and can be listed alone', function () {
    $this->post(route('invoices.store', $this->job), documentPayload([
        'issued_on' => now('America/Vancouver')->subDays(40)->toDateString(),
        'due_on' => now('America/Vancouver')->subDays(10)->toDateString(),
    ]))->assertSessionHasNoErrors();
    $this->post(route('invoices.store', $this->job), documentPayload([
        'due_on' => now('America/Vancouver')->addDays(10)->toDateString(),
    ]))->assertSessionHasNoErrors();
    [$late, $current] = inCompany($this->company, fn () => Invoice::query()->orderBy('id')->get()->all());

    // Another company's overdue invoice never shows up.
    $other = Company::factory()->create();
    [$otherOwner, $otherJob] = inCompany($other, fn () => [
        memberOf($other),
        ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))->create(),
    ]);
    $this->actingAs($otherOwner)->post(route('invoices.store', $otherJob), documentPayload([
        'issued_on' => now('America/Vancouver')->subDays(40)->toDateString(),
        'due_on' => now('America/Vancouver')->subDays(10)->toDateString(),
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get(route('invoices.show', $late))
        ->assertInertia(fn (Assert $page) => $page->where('document.overdue', true));
    $this->get(route('invoices.show', $current))
        ->assertInertia(fn (Assert $page) => $page->where('document.overdue', false));
    $this->get(route('invoices.index', ['status' => 'overdue']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.id', $late->id)
            ->where('invoices.data.0.overdue', true));

    inCompany($this->company, fn () => $late->forceFill(['status' => InvoiceStatus::Paid, 'balance' => 0])->save());
    $this->get(route('invoices.show', $late))
        ->assertInertia(fn (Assert $page) => $page->where('document.overdue', false));
});

test('a second invoice on a job shows the invoices it already has', function () {
    $this->get(route('invoices.create', $this->job))
        ->assertInertia(fn (Assert $page) => $page->has('existingInvoices', 0));

    $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
    $invoice = inCompany($this->company, fn () => Invoice::query()->sole());

    $this->get(route('invoices.create', $this->job))
        ->assertInertia(fn (Assert $page) => $page
            ->has('existingInvoices', 1)
            ->where('existingInvoices.0.number', $invoice->number));
    $this->get(route('invoices.start'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.id', $this->job->id)
            ->where('jobs.0.invoices', [$invoice->number])
            ->where('jobs.0.technicians', [$this->owner->name])
            ->has('jobs.0.status_label')
            ->has('jobs.0.address'));
});

test('a business address typed with the city and postal code in the street line is shown once', function () {
    expect(AddressFormatter::street('140 6th St, New Westminster, V3l2z9', '514', 'New Westminster', 'CA'))
        ->toBe('Unit 514, 140 6th St')
        ->and(AddressFormatter::street('140 6th St', 'Suite 200, rear entrance', 'New Westminster', 'CA'))
        ->toBe('140 6th St, Suite 200, rear entrance')
        ->and(AddressFormatter::street('10 Main St', null, 'Austin', 'US'))
        ->toBe('10 Main St');

    inCompany($this->company, fn () => $this->brand->addresses()->create([
        'line1' => '140 6th St, New Westminster, V3l2z9',
        'line2' => '514',
        'city' => 'New Westminster',
        'region' => 'BC',
        'postal_code' => 'v3l 3l4',
        'country' => 'CA',
        'is_primary' => true,
    ]));

    $brand = inCompany($this->company, fn () => $this->brand->fresh()->load('addresses'));

    expect(DocumentPrint::brand($brand, 'CA')['address'])
        ->toBe('Unit 514, 140 6th St, New Westminster, BC V3L 3L4');
});
