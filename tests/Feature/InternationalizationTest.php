<?php

use App\Enums\PaymentTerms;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * SPEC §1.1–1.2: nothing assumes Canada; country, currency, taxes, formats and vertical are per company.
 */
function newCompany(array $overrides = []): Company
{
    Notification::fake();

    test()->actingAs(User::factory()->superAdmin()->create())
        ->post(route('admin.companies.store'), [
            'name' => 'Acme Repair',
            'owner_name' => 'Ann',
            'owner_email' => 'ann@example.com',
            'currency' => $overrides['currency'] ?? 'USD',
            ...$overrides,
        ])
        ->assertSessionHasNoErrors();

    return Company::where('name', 'Acme Repair')->sole();
}

test('a company created in the USA gets US defaults', function () {
    $company = newCompany(['country' => 'US']);

    expect($company)
        ->country->toBe('US')
        ->currency->toBe('USD')
        ->locale->toBe('en-US')
        ->timezone->toBe('America/New_York')
        ->timezone_pending->toBeTrue()
        ->default_payment_terms->toBe(PaymentTerms::DueOnReceipt)
        ->and($company->vertical->value)->toBe('appliance_repair')
        // No taxes are created for anyone: each company sets up its own.
        ->and(TaxRate::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(0);
});

test('the super-admin picks country, currency, regional format and vertical when creating a company', function () {
    $company = newCompany(['country' => 'GB', 'currency' => 'GBP', 'locale' => 'en-GB', 'vertical' => 'handyman', 'timezone' => 'Europe/London']);

    expect($company)->country->toBe('GB')->currency->toBe('GBP')->locale->toBe('en-GB')
        ->and($company->vertical->value)->toBe('handyman');

    $this->get(route('admin.companies.create'))->assertInertia(fn (Assert $page) => $page
        ->where('countries.0.value', 'US')
        ->where('countries.1.value', 'CA')
        ->where('countryDefaults.DE.currency', 'EUR')
        ->has('verticals', 2));
});

test('a handyman company gets its own job types, checklists and services', function () {
    $company = newCompany(['country' => 'US', 'vertical' => 'handyman']);
    $owner = User::where('email', 'ann@example.com')->sole();

    $types = ChecklistTemplate::withoutCompanyScope()->where('company_id', $company->id)->pluck('job_type')->map->value->sort()->values()->all();
    expect($types)->toBe(['assembly', 'inspection', 'installation', 'maintenance', 'mounting', 'repair'])
        ->and(Service::withoutCompanyScope()->where('company_id', $company->id)->pluck('name'))->toContain('TV mounting', 'Furniture assembly');

    $owner->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('x')])->save();
    $this->actingAs($owner)->get(route('company.checklists.edit'))
        ->assertInertia(fn (Assert $page) => $page->has('jobTypes', 6)->where('jobTypes.2.value', 'assembly'));
});

test('an appliance repair company starts with appliance services', function () {
    $company = newCompany(['country' => 'CA', 'currency' => 'CAD']);

    expect(Service::withoutCompanyScope()->where('company_id', $company->id)->pluck('name'))
        ->toContain('Service call / diagnosis', 'Dryer vent cleaning')
        ->and(Service::withoutCompanyScope()->where('company_id', $company->id)->whereNotNull('unit_price')->count())->toBe(0);
});

describe('in a company', function () {
    beforeEach(function () {
        $this->company = Company::factory()->inUnitedStates()->create();
        $this->owner = memberOf($this->company, UserRole::Owner);
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->for($this->company)->create(['first_name' => 'Maria']);
        $this->property = Property::factory()->for($this->customer)->create([
            'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ]);
        $this->job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
        $this->actingAs($this->owner);
    });

    test('shared props carry the company formats', function () {
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.company.country', 'US')
            ->where('auth.company.currency', 'USD')
            ->where('auth.company.currency_decimals', 2)
            ->where('auth.company.locale', 'en-US')
            ->where('auth.company.address.region_label', 'State')
            ->where('auth.company.address.postal_label', 'ZIP code'));
    });

    test('national phone numbers are read in the company country and stored in E.164', function () {
        $this->post(route('customers.store'), [
            'type' => 'residential', 'first_name' => 'Kevin',
            'phones' => [['label' => 'mobile', 'number' => '(512) 555-0123', 'is_primary' => true]],
            'emails' => [],
            'add_property' => true,
            'property' => ['line1' => '1100 E 6th St', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78702'],
        ])->assertSessionHasNoErrors();

        $kevin = inCompany($this->company, fn () => Customer::where('first_name', 'Kevin')->with('phones', 'properties')->sole());
        expect($kevin->phones->sole()->number)->toBe('+15125550123')
            ->and($kevin->properties->sole()->country)->toBe('US')
            ->and($kevin->properties->sole()->fullAddress())->toBe('1100 E 6th St, Austin, TX 78702');
    });

    test('postal codes are checked against the format of the address country', function () {
        $payload = fn (array $property) => [
            'type' => 'residential', 'first_name' => 'Zed', 'phones' => [], 'emails' => [], 'add_property' => true,
            'property' => ['line1' => '1 Main St', 'city' => 'Austin', ...$property],
        ];

        $this->post(route('customers.store'), $payload(['postal_code' => 'V3W 4G1', 'country' => 'US']))
            ->assertSessionHasErrors(['property.postal_code' => 'Enter a valid zip code.']);
        $this->post(route('customers.store'), $payload(['postal_code' => 'V3W 4G1', 'country' => 'CA']))
            ->assertSessionHasNoErrors();
        // Countries without a known format accept any code.
        $this->post(route('customers.store'), $payload(['postal_code' => 'ANY 123', 'country' => 'BR']))
            ->assertSessionHasNoErrors();
    });

    test('documents and payments are stored with the company currency', function () {
        $this->post(route('invoices.store', $this->job), documentPayload())->assertSessionHasNoErrors();
        $invoice = inCompany($this->company, fn () => Invoice::sole());

        $this->post(route('payments.store', $invoice), ['amount' => '80.50', 'method' => 'bank_transfer', 'reference' => 'ZELLE-1'])
            ->assertSessionHasNoErrors();

        expect($invoice->currency)->toBe('USD')
            ->and(inCompany($this->company, fn () => Payment::sole()))->currency->toBe('USD')->amount->toBe(8050);
    });

    test('a later currency change does not change existing documents', function () {
        $this->post(route('invoices.store', $this->job), documentPayload());
        $this->company->update(['currency' => 'EUR']);
        $this->post(route('estimates.store', $this->job), documentPayload());

        $invoice = inCompany($this->company, fn () => Invoice::sole());
        expect($invoice->currency)->toBe('USD')
            ->and(inCompany($this->company, fn () => Estimate::sole())->currency)->toBe('EUR');

        $this->get(route('invoices.index', ['status' => 'outstanding']))
            ->assertInertia(fn (Assert $page) => $page->where('outstandingTotals', [['currency' => 'USD', 'amount' => 28050]]));
    });

    test('amounts follow the decimals of the currency', function () {
        $this->company->update(['currency' => 'JPY', 'locale' => 'ja-JP']);

        $this->post(route('invoices.store', $this->job), documentPayload([
            'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '12.50', 'taxable' => false]],
        ]))->assertSessionHasErrors('items.0.unit_price');

        $this->post(route('invoices.store', $this->job), documentPayload([
            'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '12000', 'taxable' => false]],
        ]))->assertSessionHasNoErrors();

        expect(inCompany($this->company, fn () => Invoice::sole()))->total->toBe(12000)->currency->toBe('JPY');
    });

    test('compound taxes and tax-inclusive prices are applied on documents', function () {
        $this->company->update(['prices_include_tax' => true]);
        $vat = inCompany($this->company, fn () => TaxRate::create(['name' => 'VAT', 'rate' => 20]));

        $this->post(route('invoices.store', $this->job), documentPayload([
            'tax_rate_ids' => [$vat->id],
            'items' => [['description' => 'Repair', 'quantity' => '1', 'unit_price' => '120.00', 'taxable' => true]],
        ]))->assertSessionHasNoErrors();

        expect(inCompany($this->company, fn () => Invoice::sole()))
            ->prices_include_tax->toBeTrue()
            ->total->toBe(12000)
            ->tax_total->toBe(2000);
    });

    test('a compound tax is set up on the taxes page', function () {
        $this->post(route('taxes.store'), ['name' => 'QST', 'rate' => '9.975', 'is_compound' => true])->assertSessionHasNoErrors();

        expect(inCompany($this->company, fn () => TaxRate::sole())->is_compound)->toBeTrue();
    });
});
