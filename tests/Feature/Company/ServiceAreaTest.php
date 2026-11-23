<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create(['country' => 'CA']);
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

const LOWER_MAINLAND = ['south' => 49.0, 'north' => 49.5, 'west' => -123.35, 'east' => -121.3];

test('the owner saves the service area and every page gets it for address suggestions', function () {
    $this->put(route('company.service-area.update'), LOWER_MAINLAND)
        ->assertRedirect(route('company.service-area.edit'));

    expect($this->company->fresh()->serviceArea())->toEqual(LOWER_MAINLAND)
        ->and(AuditLog::where('action', 'company.service_area_updated')->exists())->toBeTrue();

    $this->get(route('company.service-area.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('company/service-area')
        // JSON writes 49.0 as 49: compared loosely.
        ->where('area', fn ($area) => collect($area)->all() == LOWER_MAINLAND)
        ->where('auth.company.service_area', fn ($area) => collect($area)->all() == LOWER_MAINLAND)
        ->where('auth.company.country', 'CA'));
});

test('clearing all four sides removes the limit (whole country)', function () {
    $this->company->update(['service_area_south' => 49, 'service_area_north' => 49.5, 'service_area_west' => -123.35, 'service_area_east' => -121.3]);

    $this->put(route('company.service-area.update'), ['south' => null, 'north' => null, 'west' => null, 'east' => null])
        ->assertSessionHasNoErrors();

    expect($this->company->fresh()->serviceArea())->toBeNull();
});

test('the service area is validated', function () {
    $this->put(route('company.service-area.update'), ['south' => 49.5, 'north' => 49.0, 'west' => -121.3, 'east' => -123.35])
        ->assertSessionHasErrors(['north', 'east']);
    $this->put(route('company.service-area.update'), ['south' => 95, 'north' => 49.0, 'west' => null, 'east' => -190])
        ->assertSessionHasErrors(['south', 'west', 'east']);

    expect($this->company->fresh()->serviceArea())->toBeNull();
});

test('reset to default puts back the configured default area', function () {
    config(['services.google_maps.default_service_area' => '49.00,49.50,-123.35,-121.30']);
    $this->company->update(['service_area_south' => 10, 'service_area_north' => 20, 'service_area_west' => 30, 'service_area_east' => 40]);

    $this->post(route('company.service-area.reset'))->assertRedirect(route('company.service-area.edit'));

    expect($this->company->fresh()->serviceArea())->toEqual(LOWER_MAINLAND);
});

test('reset without a configured default removes the limit', function () {
    config(['services.google_maps.default_service_area' => null]);
    $this->company->update(['service_area_south' => 10, 'service_area_north' => 20, 'service_area_west' => 30, 'service_area_east' => 40]);

    $this->post(route('company.service-area.reset'));

    expect($this->company->fresh()->serviceArea())->toBeNull();
});

test('a malformed default is ignored', function () {
    config(['services.google_maps.default_service_area' => '49.5,49.0,-123,-121']);
    expect(Company::defaultServiceArea())->toBeNull();

    config(['services.google_maps.default_service_area' => 'abc']);
    expect(Company::defaultServiceArea())->toBeNull();
});

test('only the owner can change the service area', function () {
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->get(route('company.service-area.edit'))->assertForbidden();
    $this->put(route('company.service-area.update'), LOWER_MAINLAND)->assertForbidden();
    $this->post(route('company.service-area.reset'))->assertForbidden();

    expect($this->company->fresh()->serviceArea())->toBeNull();
});

test("the service area is per company: another company's box never leaks", function () {
    $this->put(route('company.service-area.update'), LOWER_MAINLAND);

    app(CurrentCompany::class)->forget();
    $other = Company::factory()->create(['country' => 'US']);
    $this->actingAs(memberOf($other, UserRole::Owner));

    $this->get(route('company.service-area.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('area', null)
        ->where('auth.company.service_area', null));
});
