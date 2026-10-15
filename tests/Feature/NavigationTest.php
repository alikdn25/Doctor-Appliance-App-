<?php

use App\Enums\UserRole;
use App\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->company = Company::factory()->create();
});

test('owners and admins start in the Office menu and can switch to Field; it is saved for the person', function () {
    $owner = memberOf($this->company, UserRole::Owner);

    $this->actingAs($owner)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.menu_mode', 'office')
        ->where('auth.user.can_choose_menu', true));

    $this->put(route('menu-mode.update'), ['mode' => 'field'])->assertRedirect();
    expect($owner->fresh()->menu_mode)->toBe('field');

    $this->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page->where('auth.user.menu_mode', 'field'));

    $this->put(route('menu-mode.update'), ['mode' => 'nonsense'])->assertSessionHasErrors('mode');
});

test('technicians always use the Field menu and cannot switch', function () {
    $tech = memberOf($this->company, UserRole::Technician);

    $this->actingAs($tech)->get(route('jobs.mine'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.menu_mode', 'field')
        ->where('auth.user.can_choose_menu', false));

    $this->put(route('menu-mode.update'), ['mode' => 'office'])->assertForbidden();
    expect($tech->fresh()->menu_mode)->toBeNull();
});

test('Expenses & taxes gathers expenses, mileage and cash for the Owner only', function () {
    $owner = memberOf($this->company, UserRole::Owner);

    $this->actingAs($owner)->get(route('expenses-hub'))->assertInertia(fn (Assert $page) => $page
        ->component('expenses/hub')
        ->where('expenses', 0)
        ->where('cash', 0)
        ->has('distanceUnit'));

    $admin = memberOf($this->company, UserRole::Admin);
    $this->actingAs($admin)->get(route('expenses-hub'))->assertForbidden();
    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->get(route('expenses-hub'))->assertForbidden();
});

test('the unfinished-jobs strip and the SMS mode reach every screen for the menu', function () {
    $owner = memberOf($this->company, UserRole::Owner);

    $this->actingAs($owner)->get(route('customers.index'))->assertInertia(fn (Assert $page) => $page
        ->has('unfinishedJobs.counts.overdue')
        ->has('auth.company.sms_mode'));
});
