<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a user in several companies works in their current company and can switch', function () {
    $first = Company::factory()->create(['name' => 'First Co']);
    $second = Company::factory()->create(['name' => 'Second Co']);
    Brand::factory()->create(['company_id' => $first->id, 'name' => 'First Brand']);
    Brand::factory()->create(['company_id' => $second->id, 'name' => 'Second Brand']);

    $user = memberOf($first, UserRole::Owner);
    Membership::factory()->create(['company_id' => $second->id, 'user_id' => $user->id, 'role' => UserRole::Admin]);

    $this->actingAs($user)
        ->get(route('brands.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.company.name', 'First Co')
            ->where('auth.role.value', 'owner')
            ->has('auth.companies', 2)
            ->where('brands.0.name', 'First Brand'));

    $this->post(route('companies.switch', $second))->assertRedirect(route('calendar', absolute: false));

    expect($user->fresh()->current_company_id)->toBe($second->id);

    $this->get(route('brands.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.company.name', 'Second Co')
            ->where('auth.role.value', 'admin')
            ->has('brands', 1)
            ->where('brands.0.name', 'Second Brand'));

    // Admins can open the team, but cannot change Owners or other Admins.
    $this->get(route('team.index'))->assertOk();
});

test('a user cannot switch to a company they do not belong to', function () {
    $user = memberOf();
    $other = Company::factory()->create();

    $this->actingAs($user)->post(route('companies.switch', $other))->assertForbidden();

    expect($user->fresh()->current_company_id)->not->toBe($other->id);
});

test('a user cannot switch to a company where their membership is inactive', function () {
    $user = memberOf();
    $other = Company::factory()->create();
    Membership::factory()->create(['company_id' => $other->id, 'user_id' => $user->id, 'is_active' => false]);

    $this->actingAs($user)->post(route('companies.switch', $other))->assertForbidden();
});

test('a stale current company falls back to an accessible one', function () {
    $company = Company::factory()->create();
    $user = memberOf($company);
    $removed = Company::factory()->create();
    $user->forceFill(['current_company_id' => $removed->id])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.company.id', $company->id));

    expect($user->fresh()->current_company_id)->toBe($company->id);
});

test('a new user without any membership is guided to company setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.company.create'));
});

test('members of a suspended company are locked out', function () {
    $company = Company::factory()->suspended()->create();
    $user = memberOf($company);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/no-company')
            ->where('hasSuspendedCompany', true));
});

test('a deactivated membership blocks access to that company', function () {
    $company = Company::factory()->create();
    $user = memberOf($company, UserRole::Technician);
    Membership::withoutCompanyScope()->where('user_id', $user->id)->update(['is_active' => false]);

    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});
