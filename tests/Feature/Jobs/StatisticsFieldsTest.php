<?php

use App\Enums\ApplianceCategory;
use App\Enums\JobOutcome;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Statistics\ApplianceGuess;
use App\Support\Tenancy\CurrentCompany;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The job's fields for Statistics (owner's task of November 24): appliance type and brand, Only diagnostic, and the
 * customer's source becoming Repeat.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create(['lead_source' => null]);
    $this->property = Property::factory()->for($this->customer)->create();
    $this->actingAs($this->owner);
});

test('the job takes the type and brand of its appliance; a later rating plate fills the brand', function () {
    $appliance = Appliance::factory()->for($this->property)->create(['type' => 'range', 'manufacturer' => null]);
    $job = ServiceJob::factory()->for($this->property)->withAppliances([$appliance])->create(['brand_id' => $this->brand->id]);

    $fresh = fn () => ServiceJob::withoutCompanyScope()->find($job->id);
    expect($fresh()->appliance_category)->toBe(ApplianceCategory::RangeOven)
        ->and($fresh()->appliance_brand)->toBeNull();

    inCompany($this->company, fn () => $appliance->update(['manufacturer' => 'lg', 'type' => 'oven']));
    expect($fresh()->appliance_brand)->toBe('LG')
        ->and($fresh()->appliance_category)->toBe(ApplianceCategory::WallOven);

    // A value chosen by hand stays.
    $this->put(route('jobs.statistics', $job), ['appliance_category' => 'cooktop', 'appliance_brand' => 'Bosch'])->assertRedirect();
    inCompany($this->company, fn () => $appliance->update(['manufacturer' => 'Samsung', 'type' => 'range']));
    expect($fresh()->appliance_category)->toBe(ApplianceCategory::Cooktop)
        ->and($fresh()->appliance_brand)->toBe('Bosch');
});

test('the job page shows and saves the fields; the technician on the job may set them, another company may not', function () {
    $job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);

    $this->get(route('jobs.show', $job))->assertInertia(fn (Assert $page) => $page
        ->where('job.stats.appliance_category', null)
        ->where('job.stats.only_diagnostic', false)
        ->has('applianceCategories', 11));

    $this->actingAs($this->tech)->put(route('jobs.statistics', $job), ['only_diagnostic' => true, 'appliance_category' => 'washer'])
        ->assertSessionHasNoErrors();
    expect(ServiceJob::withoutCompanyScope()->find($job->id))
        ->only_diagnostic->toBeTrue()
        ->appliance_category->toBe(ApplianceCategory::Washer);

    $this->put(route('jobs.statistics', $job), ['appliance_category' => 'toaster'])->assertSessionHasErrors('appliance_category');

    app(CurrentCompany::class)->forget();
    $this->actingAs(memberOf(Company::factory()->create()))->put(route('jobs.statistics', $job), ['only_diagnostic' => false])->assertNotFound();
});

test('closing as customer declined marks the job only diagnostic', function () {
    $job = ServiceJob::factory()->for($this->property)->withVisit($this->tech)->create(['brand_id' => $this->brand->id]);
    $reason = inCompany($this->company, fn () => $this->company->closureReasons(JobOutcome::CustomerDeclined)[0]);

    $this->post(route('jobs.close', $job), ['outcome' => 'customer_declined', 'reason' => $reason])->assertSessionHasNoErrors();

    expect(ServiceJob::withoutCompanyScope()->find($job->id)->only_diagnostic)->toBeTrue();
});

test('a customer with an earlier job becomes Repeat when no source was chosen', function () {
    ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
    expect(Customer::withoutCompanyScope()->find($this->customer->id)->lead_source)->toBeNull();

    $second = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);

    expect(Customer::withoutCompanyScope()->find($this->customer->id)->lead_source)->toBe(LeadSource::RepeatCustomer)
        ->and(ServiceJob::withoutCompanyScope()->find($second->id)->lead_source)->toBe(LeadSource::RepeatCustomer);
});

test('type and brand are read from text only when the words are clear', function () {
    expect(ApplianceGuess::type('LG washer not draining'))->toBe(ApplianceCategory::Washer)
        ->and(ApplianceGuess::type('Dishwasher leaks'))->toBe(ApplianceCategory::Dishwasher)
        ->and(ApplianceGuess::type('не работает посудомойка'))->toBe(ApplianceCategory::Dishwasher)
        ->and(ApplianceGuess::type('Wall oven F3 error'))->toBe(ApplianceCategory::WallOven)
        ->and(ApplianceGuess::type('Customer will call back'))->toBeNull()
        ->and(ApplianceGuess::brand('whirlpool fridge, model WRF555'))->toBe('Whirlpool')
        ->and(ApplianceGuess::brand('no brand given'))->toBeNull()
        ->and(ApplianceGuess::normalizeBrand(' samsung '))->toBe('Samsung');
});
