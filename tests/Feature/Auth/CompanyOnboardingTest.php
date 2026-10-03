<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function companySetupPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'New Repair Company', 'country' => 'GB', 'vertical' => 'appliance_repair',
        'currency' => 'GBP', 'locale' => 'en-GB', 'timezone' => 'Europe/London',
    ], $overrides);
}

beforeEach(function () {
    Notification::fake();
});

test('a verified new account sees company setup instead of a forbidden screen', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('onboarding.company.create'));
    $this->get(route('onboarding.company.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('onboarding/company')->has('countries')->has('verticals')->has('timezones'));
});

test('setup creates the current users own company and first brand without granting platform privileges', function () {
    $user = User::factory()->create();
    $foreign = Company::factory()->create();
    $this->actingAs($user)->post(route('onboarding.company.store'), companySetupPayload([
        'company_id' => $foreign->id, 'owner_email' => 'foreign@example.com',
        'owner_id' => 999, 'plan' => 'forged', 'is_super_admin' => true,
    ]))->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $company = Company::where('name', 'New Repair Company')->sole();
    $membership = $user->memberships()->sole();
    expect($company->currency)->toBe('GBP')->and($company->country)->toBe('GB')
        ->and($company->timezone)->toBe('Europe/London')->and($company->locale)->toBe('en-GB')
        ->and($company->plan)->toBeNull()->and($membership->company_id)->toBe($company->id)
        ->and($membership->role)->toBe(UserRole::Owner)
        ->and($user->fresh()->current_company_id)->toBe($company->id)
        ->and($user->fresh()->is_super_admin)->toBeFalse();
    expect($company->brands()->sole()->name)->toBe($company->name);
    expect($user->membershipFor($foreign))->toBeNull();
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('brands.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('brands', 1)->where('auth.company.id', $company->id));
});

test('setup cannot be submitted again or used to add a company by an existing member', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('onboarding.company.store'), companySetupPayload())->assertRedirect();
    $this->post(route('onboarding.company.store'), companySetupPayload(['name' => 'Duplicate']))->assertForbidden();
    expect(Company::count())->toBe(1)->and(Membership::withoutCompanyScope()->count())->toBe(1)
        ->and(Brand::withoutCompanyScope()->count())->toBe(1);
    $this->get(route('onboarding.company.create'))->assertRedirect(route('dashboard'));
});

test('company setup cannot bypass suspended companies or inactive memberships', function () {
    $user = memberOf(Company::factory()->suspended()->create());
    $this->actingAs($user)->post(route('onboarding.company.store'), companySetupPayload())->assertForbidden();
    $this->get(route('dashboard'))->assertForbidden();
    $other = memberOf();
    $other->memberships()->update(['is_active' => false]);
    $this->actingAs($other)->post(route('onboarding.company.store'), companySetupPayload())->assertForbidden();
});

test('company setup validates location and cannot create partial company records', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('onboarding.company.store'), companySetupPayload([
        'country' => 'XX', 'currency' => 'INVALID', 'timezone' => 'Not/AZone', 'vertical' => 'forged',
    ]))->assertSessionHasErrors(['country', 'currency', 'timezone', 'vertical']);
    expect(Company::count())->toBe(0)->and($user->memberships()->count())->toBe(0);
});

test('super admins use the platform panel rather than public company setup', function () {
    $user = User::factory()->superAdmin()->create();
    $this->actingAs($user)->get(route('onboarding.company.create'))->assertRedirect(route('admin.companies.index'));
    $this->post(route('onboarding.company.store'), companySetupPayload())->assertForbidden();
});
