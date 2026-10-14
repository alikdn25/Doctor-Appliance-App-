<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Support\Locale\Spelling;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * UX redesign phase 1 (P0): spelling by regional format, no warranty on credits.
 */
test('the spelling follows the company regional format', function () {
    expect(Spelling::appLocale('en-US'))->toBe('en')
        ->and(Spelling::appLocale('en-CA'))->toBe('en_GB')
        ->and(Spelling::appLocale('en-GB'))->toBe('en_GB')
        ->and(Spelling::appLocale('en-AU'))->toBe('en_GB')
        ->and(Spelling::appLocale(null))->toBe('en');
});

test('a Canadian company reads Labour and Cheque, a US company Labor and Check', function () {
    $canadian = memberOf(Company::factory()->create());
    $american = memberOf(Company::factory()->inUnitedStates()->create());

    $this->actingAs($canadian)->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('translations.billing.kinds.service', 'Labour')
        ->where('translations.company.warranty_labor', 'Labour')
        ->where('translations.payments.methods.check', 'Cheque')
        // Everything not respelled falls back to the default English texts.
        ->where('translations.billing.kinds.part', 'Part'));

    $this->actingAs($american)->get(route('company.settings.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('translations.billing.kinds.service', 'Labor')
        ->where('translations.company.warranty_labor', 'Labor')
        ->where('translations.payments.methods.check', 'Check'));
});

test('the default hourly service is spelled for the new company', function () {
    $create = app(CreateCompany::class);
    $canada = $create->handle(['name' => 'Maple Repair', 'country' => 'CA'], ['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret-password']);
    $usa = $create->handle(['name' => 'Lone Star Repair', 'country' => 'US'], ['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret-password']);

    $names = fn (Company $company) => inCompany($company, fn () => Service::query()->pluck('name')->all());

    expect($names($canada))->toContain('Labour (per hour)')->not->toContain('Labor (per hour)')
        ->and($names($usa))->toContain('Labor (per hour)')->not->toContain('Labour (per hour)');
});

test('credit and discount lines show no warranty', function () {
    $company = Company::factory()->create(['warranty_labor_value' => 30, 'warranty_labor_unit' => 'days']);
    $owner = memberOf($company, UserRole::Owner);
    $brand = Brand::factory()->create(['company_id' => $company->id]);
    $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($company)))
        ->withVisit($owner)->create(['brand_id' => $brand->id]);
    $this->actingAs($owner);

    $this->post(route('estimates.store', $job), documentPayload(['items' => [
        ['description' => 'Repair', 'quantity' => '1', 'unit_price' => '200.00', 'taxable' => true],
        ['description' => 'Diagnostic fee credit', 'quantity' => '1', 'unit_price' => '-95.00', 'taxable' => true],
    ]]))->assertSessionHasNoErrors();

    $estimate = inCompany($company, fn () => Estimate::query()->sole());
    $this->get(route('estimates.show', $estimate))->assertInertia(fn (Assert $page) => $page
        ->where('document.items.0.warranty_label', '30 days')
        ->where('document.items.1.warranty_label', '')
        ->where('document.items.1.warranty_value', null));
});
