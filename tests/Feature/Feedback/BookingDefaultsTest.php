<?php

use App\Enums\UserRole;
use App\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;

test('the booking form gets the company clock so it can skip windows that have passed', function () {
    $this->travelTo('2030-06-12 22:19:00'); // 15:19 in Vancouver
    $company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    $this->actingAs(memberOf($company, UserRole::Owner));

    $this->get(route('jobs.create', ['book' => 1]))->assertInertia(fn (Assert $page) => $page
        ->component('jobs/quick-book')
        ->where('today', '2030-06-12')
        ->where('nowTime', '15:19'));
});
