<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\JobProfit;
use Inertia\Testing\AssertableInertia as Assert;

test('part and material purchase prices and differences belong only to their author', function () {
    $company = Company::factory()->create(['technicians_see_costs' => false]);
    $owner = memberOf($company);
    $first = memberOf($company, UserRole::Technician);
    $second = memberOf($company, UserRole::Technician);
    $brand = Brand::factory()->create(['company_id' => $company->id]);
    $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($company)))->withVisit([$first, $second])->create(['brand_id' => $brand->id]);
    $this->actingAs($first)->post(route('invoices.store', $job), documentPayload(['items' => [
        ['description' => 'Private part', 'kind' => 'part', 'quantity' => '1', 'unit_price' => '90.00', 'unit_cost' => '40.00'],
        ['description' => 'Private material', 'kind' => 'material', 'quantity' => '2', 'unit_price' => '12.00', 'unit_cost' => '5.00'],
    ]]))->assertSessionHasNoErrors();
    $invoice = inCompany($company, fn () => Invoice::query()->with('items')->sole());
    $this->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
        ->where('document.items.0.unit_cost', 4000)->where('document.items.0.private_difference', 5000)
        ->where('document.items.1.private_difference', 1400));
    foreach ([$owner, $second] as $viewer) {
        $this->actingAs($viewer)->get(route('invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
            ->where('document.items.0.unit_cost', null)->where('document.items.0.total_cost', null)->where('document.items.0.private_difference', null));
        $this->getJson(route('pricebook.history', ['q' => 'Private']))->assertJsonPath('history', []);
        expect(inCompany($company, fn () => JobProfit::for($job)['profit']))->toBeNull();
    }
    $print = inCompany($company, fn () => DocumentPrint::data($invoice));
    expect($print['items'][0])->not->toHaveKeys(['unit_cost', 'cost_owner_id', 'private_difference']);
    $items = $invoice->items->map(fn ($item) => ['id' => $item->id, 'kind' => $item->kind->value, 'description' => $item->description, 'quantity' => (string) $item->quantity, 'unit_price' => '99.00', 'unit_cost' => '0.01', 'cost_owner_id' => $second->id])->all();
    $this->put(route('invoices.update', $invoice), documentPayload(['items' => $items]))->assertSessionHasNoErrors();
    $saved = inCompany($company, fn () => $invoice->fresh()->items->first());
    expect($saved->unit_cost)->toBe(4000)->and($saved->cost_owner_id)->toBe($first->id)->and($saved->unit_price)->toBe(9900);
});
