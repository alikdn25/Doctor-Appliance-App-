<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentProviderConnection;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Owner's task of November 22: taxes are added on top of the line amounts by default. A small "Price includes tax"
 * switch under "+ Add line" (off on every new New charge screen, never remembered) works the ticked taxes back out of
 * the amounts. The same on invoice lines and in the "Close by voice" preview.
 */
beforeEach(function () {
    config(['services.square' => [
        'environment' => 'sandbox', 'application_id' => 'sandbox-sq0idb-app', 'application_secret' => 'sandbox-sq0csb-secret',
        'webhook_signature_key' => 'whsec-test-key', 'webhook_url' => 'https://app.example.test/webhooks/payments/square',
        'api_version' => '2025-10-16',
    ]]);

    // The company's own setting says "prices include tax": New charge and new invoices still start with taxes on top.
    $this->company = Company::factory()->create([
        'timezone' => 'America/Vancouver', 'country' => 'CA', 'currency' => 'CAD', 'prices_include_tax' => true,
        'business_hours' => Company::defaultBusinessHours(),
    ]);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->gst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'GST', 'rate' => 5, 'is_default' => true])->id;
    $this->pst = TaxRate::factory()->create(['company_id' => $this->company->id, 'name' => 'PST', 'rate' => 7, 'is_default' => true, 'sort_order' => 1])->id;
    inCompany($this->company, fn () => PaymentProviderConnection::create([
        'provider' => 'square', 'account_id' => 'MLR1', 'account_name' => 'Doctor Appliance',
        'location_id' => 'LOC1', 'location_name' => 'Surrey', 'currency' => 'CAD',
        'access_token' => 'EAAAl-access-1', 'refresh_token' => 'EQAAl-refresh-1', 'token_expires_at' => now()->addDays(30),
    ]));
    $this->company->update(['payment_provider' => 'square']);

    $this->actingAs($this->owner);
});

function taxLines(): array
{
    return [
        ['description' => 'Dryer repair', 'amount' => '250', 'tax_rate_ids' => [test()->gst]],
        ['description' => 'Heating element', 'amount' => '110', 'tax_rate_ids' => [test()->gst, test()->pst]],
    ];
}

test('a new New charge screen opens with "Price includes tax" off, every time', function () {
    $this->get(route('charges.create'))->assertInertia(fn (Assert $page) => $page
        ->component('charges/new')
        ->where('price_includes_tax', false));

    // Using the switch once is not remembered.
    $this->postJson(route('charges.preview'), ['lines' => taxLines(), 'amounts_include_tax' => true])->assertOk();
    $this->get(route('charges.create'))->assertInertia(fn (Assert $page) => $page->where('price_includes_tax', false));
});

test('switch off: taxes on top — 250 (GST) + 110 (GST+PST) = 385.70', function () {
    $this->postJson(route('charges.preview'), ['lines' => taxLines(), 'amounts_include_tax' => false])
        ->assertOk()
        ->assertJsonPath('subtotal', '$360.00')
        ->assertJsonPath('total', '$385.70')
        ->assertJsonPath('total_minor', 38570);
});

test('switch on: the amounts already include the taxes — Total 360.00', function () {
    $this->postJson(route('charges.preview'), ['lines' => taxLines(), 'amounts_include_tax' => true])
        ->assertOk()
        ->assertJsonPath('total', '$360.00')
        ->assertJsonPath('total_minor', 36000);
});

test('invoice lines: the switch is saved on the invoice; off = taxes on top, on = taxes inside', function () {
    $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->withVisit($this->owner)->create(['brand_id' => $this->brand->id]);
    $items = [
        ['description' => 'Dryer repair', 'quantity' => '1', 'unit_price' => '250.00', 'taxable' => true, 'tax_rate_ids' => [$this->gst]],
        ['description' => 'Heating element', 'quantity' => '1', 'unit_price' => '110.00', 'taxable' => true, 'tax_rate_ids' => [$this->gst, $this->pst]],
    ];
    $latest = fn () => inCompany($this->company, fn () => Invoice::latest('id')->firstOrFail());

    $this->post(route('invoices.store', $job), documentPayload(['tax_rate_ids' => [$this->gst, $this->pst], 'items' => $items, 'prices_include_tax' => false]))
        ->assertRedirect();
    $off = $latest();
    expect($off->prices_include_tax)->toBeFalse()->and($off->total)->toBe(38570);

    $this->post(route('invoices.store', $job), documentPayload(['tax_rate_ids' => [$this->gst, $this->pst], 'items' => $items, 'prices_include_tax' => true]))
        ->assertRedirect();
    $on = $latest();
    expect($on->prices_include_tax)->toBeTrue()->and($on->total)->toBe(36000);

    // Switched off when editing: the taxes go back on top.
    $this->put(route('invoices.update', $on), documentPayload(['tax_rate_ids' => [$this->gst, $this->pst], 'items' => $items, 'prices_include_tax' => false]))
        ->assertRedirect();
    expect($on->fresh()->prices_include_tax)->toBeFalse()->and($on->fresh()->total)->toBe(38570);
});
