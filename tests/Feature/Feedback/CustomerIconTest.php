<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->customer = Customer::factory()->for($this->company)->create(['first_name' => 'Sasha', 'type' => 'residential']);
});

test('the office can pick a man or woman icon for a name that fits both', function () {
    $this->actingAs(memberOf($this->company, UserRole::Admin));

    $this->patch(route('customers.icon', $this->customer), ['avatar_style' => 'woman'])->assertRedirect();

    expect($this->customer->fresh()->avatar_style)->toBe('woman')
        ->and($this->customer->fresh()->avatarIcon())->toBe('woman');

    $this->patch(route('customers.icon', $this->customer), ['avatar_style' => 'robot'])->assertSessionHasErrors('avatar_style');
    $this->patch(route('customers.icon', $this->customer), ['avatar_style' => 'neutral'])->assertSessionHasErrors('avatar_style');
});

test('the icon of another company customer cannot be changed', function () {
    $this->actingAs(memberOf(Company::factory()->create(), UserRole::Owner));

    $this->patch(route('customers.icon', $this->customer), ['avatar_style' => 'man'])->assertNotFound();
    expect($this->customer->fresh()->avatar_style)->toBe('auto');
});

test('technicians cannot change customer icons', function () {
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->patch(route('customers.icon', $this->customer), ['avatar_style' => 'man'])->assertForbidden();
});
