<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\TaxRate;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->tax = TaxRate::factory()->create(['company_id' => $this->company->id]);
});

test('owners can open every company page', function () {
    $this->actingAs(memberOf($this->company, UserRole::Owner));

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('brands.index'))->assertOk();
    $this->get(route('brands.create'))->assertOk();
    $this->get(route('team.index'))->assertOk();
    $this->get(route('taxes.index'))->assertOk();
    $this->get(route('company.settings.edit'))->assertOk();
});

test('admins can view brands and taxes but not change them, the team or settings', function () {
    $this->actingAs(memberOf($this->company, UserRole::Admin));

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('brands.index'))->assertOk();
    $this->get(route('brands.edit', $this->brand))->assertOk();
    $this->get(route('taxes.index'))->assertOk();

    $this->get(route('brands.create'))->assertForbidden();
    $this->post(route('brands.store'), ['name' => 'X'])->assertForbidden();
    $this->put(route('brands.update', $this->brand), ['name' => 'X'])->assertForbidden();
    $this->delete(route('brands.destroy', $this->brand))->assertForbidden();
    $this->post(route('taxes.store'), ['name' => 'X', 'rate' => 1])->assertForbidden();
    $this->put(route('taxes.update', $this->tax), ['name' => 'X', 'rate' => 1])->assertForbidden();
    $this->get(route('team.index'))->assertForbidden();
    $this->post(route('team.store'), [])->assertForbidden();
    $this->get(route('company.settings.edit'))->assertForbidden();
    $this->put(route('company.settings.update'), [])->assertForbidden();
});

test('technicians only get the dashboard', function () {
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->get(route('dashboard'))->assertOk();

    $this->get(route('brands.index'))->assertForbidden();
    $this->get(route('brands.edit', $this->brand))->assertForbidden();
    $this->get(route('team.index'))->assertForbidden();
    $this->get(route('taxes.index'))->assertForbidden();
    $this->get(route('company.settings.edit'))->assertForbidden();
});

test('the shared permissions match the role', function (UserRole $role, array $can) {
    $this->actingAs(memberOf($this->company, $role))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.can', $can));
})->with([
    'owner' => [UserRole::Owner, ['viewCustomers' => true, 'viewJobs' => true, 'viewMyJobs' => true, 'viewCalendar' => true, 'manageChecklists' => true, 'manageCompany' => true, 'viewBrands' => true, 'manageTeam' => true, 'viewTaxes' => true]],
    'admin' => [UserRole::Admin, ['viewCustomers' => true, 'viewJobs' => true, 'viewMyJobs' => true, 'viewCalendar' => true, 'manageChecklists' => true, 'manageCompany' => false, 'viewBrands' => true, 'manageTeam' => false, 'viewTaxes' => true]],
    'technician' => [UserRole::Technician, ['viewCustomers' => false, 'viewJobs' => false, 'viewMyJobs' => true, 'viewCalendar' => false, 'manageChecklists' => false, 'manageCompany' => false, 'viewBrands' => false, 'manageTeam' => false, 'viewTaxes' => false]],
]);

test('guests are sent to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('brands.index'))->assertRedirect(route('login'));
    $this->get(route('admin.companies.index'))->assertRedirect(route('login'));
});
