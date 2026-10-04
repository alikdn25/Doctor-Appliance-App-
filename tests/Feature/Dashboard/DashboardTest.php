<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\ServiceJob;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('a new company sees the first steps and no work yet', function () {
    $this->actingAs(memberOf())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('isNew', true)
        ->where('today.visits', 0)
        ->where('today.unpaid.count', 0));
});

test('the dashboard shows today\'s visits, and unpaid invoices of this company only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'UTC'));
    $company = Company::factory()->create(['timezone' => 'UTC']);
    $owner = memberOf($company);
    $tech = memberOf($company, UserRole::Technician);
    $job = inCompany($company, fn () => ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($company)))
        ->withVisit($tech, ['scheduled_start' => now()->setTime(13, 0), 'scheduled_end' => now()->setTime(15, 0)])->create());
    // Another company's work never appears.
    $other = Company::factory()->create();
    $otherTech = memberOf($other, UserRole::Technician);
    inCompany($other, fn () => ServiceJob::factory()->for(Property::factory()->for(Customer::factory()->for($other)))
        ->withVisit($otherTech, ['scheduled_start' => now()->setTime(10, 0), 'scheduled_end' => now()->setTime(11, 0)])->create());

    $this->actingAs($owner)->post(route('invoices.store', $job), documentPayload())->assertSessionHasNoErrors();

    $balance = inCompany($company, fn () => Invoice::sole()->balance);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('isNew', false)
        ->where('today.visits', 1)
        ->where('today.unpaid.count', 1)
        ->where('today.unpaid.totals.0.amount', $balance));

    // Technicians do not see invoice totals.
    $this->actingAs($tech)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('today.visits', 1)->where('today.unpaid', null));
});
