<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Service;
use App\Models\ServiceJob;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
    inCompany($this->company, fn () => Service::createDefaults());
    $this->actingAs(memberOf($this->company, UserRole::Owner));
});

test('the office sees the starting services and sets prices in the company currency', function () {
    $this->get(route('company.services.edit'))->assertInertia(fn (Assert $page) => $page
        ->component('company/services')
        ->where('services.0.name', 'Service call / diagnosis')
        ->where('services.0.unit_price', null));

    $first = inCompany($this->company, fn () => Service::orderBy('sort_order')->first());

    $this->put(route('company.services.update'), ['services' => [
        ['id' => $first->id, 'name' => 'Service call', 'unit_price' => '95.00', 'taxable' => true, 'is_active' => true],
        ['id' => null, 'name' => 'Ice maker repair', 'unit_price' => '', 'taxable' => true, 'is_active' => true],
    ]])->assertRedirect(route('company.services.edit'));

    $services = inCompany($this->company, fn () => Service::orderBy('sort_order')->get());
    expect($services)->toHaveCount(2)
        ->and($services[0])->id->toBe($first->id)->name->toBe('Service call')->unit_price->toBe(9500)
        ->and($services[1])->name->toBe('Ice maker repair')->unit_price->toBeNull();
});

test('technicians cannot change services', function () {
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->get(route('company.services.edit'))->assertForbidden();
    $this->put(route('company.services.update'), ['services' => []])->assertForbidden();
});

test('price book categories are saved, trimmed and offered on billing lines', function () {
    $this->put(route('company.services.update'), ['services' => [
        ['name' => 'Drain pump', 'kind' => 'part', 'category' => '  Dishwasher parts  ', 'unit_price' => '185.50'],
        ['name' => 'Diagnosis', 'category' => '   ', 'unit_price' => '95.00'],
    ]])->assertSessionHasNoErrors();

    $this->get(route('company.services.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('services.0.category', 'Dishwasher parts')
        ->where('services.1.category', null));

    $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->create(['brand_id' => Brand::factory()->create(['company_id' => $this->company->id])->id]);
    foreach (['invoices.create', 'estimates.create'] as $route) {
        $this->get(route($route, $job))->assertInertia(fn (Assert $page) => $page
            ->where('services.0.category', 'Dishwasher parts'));
    }
});

test('category length is validated without changing the existing catalogue', function () {
    $count = inCompany($this->company, fn () => Service::query()->count());
    $this->put(route('company.services.update'), ['services' => [
        ['name' => 'Pump', 'category' => str_repeat('x', 81)],
    ]])->assertSessionHasErrors('services.0.category');
    expect(inCompany($this->company, fn () => Service::query()->count()))->toBe($count);
});

test('categories from another company do not appear in this company catalogue', function () {
    $other = Company::factory()->create();
    inCompany($other, fn () => Service::query()->create(['name' => 'Secret part', 'category' => 'Private category']));
    $this->get(route('company.services.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('services', fn ($services) => ! collect($services)->contains('category', 'Private category')));
});

test('services of another company cannot be changed through their ids', function () {
    $other = Company::factory()->create();
    $foreign = inCompany($other, function () {
        Service::createDefaults();

        return Service::first();
    });

    $this->put(route('company.services.update'), ['services' => [
        ['id' => $foreign->id, 'name' => 'Hijacked', 'taxable' => true, 'is_active' => true],
    ]])->assertRedirect();

    expect(Service::withoutCompanyScope()->find($foreign->id)->name)->not->toBe('Hijacked')
        ->and(inCompany($this->company, fn () => Service::sole()->name))->toBe('Hijacked');
});

test('active services are offered on estimate and invoice lines', function () {
    $first = inCompany($this->company, function () {
        Service::query()->orderBy('sort_order')->first()->update(['unit_price' => 9500]);
        Service::query()->orderBy('sort_order')->skip(1)->first()->update(['is_active' => false]);

        return Service::query()->orderBy('sort_order')->first();
    });
    $job = ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($this->company)))
        ->create(['brand_id' => Brand::factory()->create(['company_id' => $this->company->id])->id]);
    $count = inCompany($this->company, fn () => Service::where('is_active', true)->count());

    foreach (['invoices.create', 'estimates.create'] as $route) {
        $this->get(route($route, $job))->assertInertia(fn (Assert $page) => $page
            ->has('services', $count)
            ->where('services.0.id', $first->id)
            ->where('services.0.unit_price', 9500)
            ->where('services.0.currency', 'CAD'));
    }
});
