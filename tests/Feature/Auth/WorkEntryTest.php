<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\ImpersonationLog;
use App\Models\User;
use App\Support\Locale\Timezones;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

test('sign-in opens Jobs for the office and My Jobs for technicians, and supports persistent login', function (UserRole $role) {
    $user = memberOf(role: $role);
    $screen = $role === UserRole::Technician ? 'jobs.mine' : 'jobs.index';
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true])
        ->assertRedirect(route($screen, absolute: false))
        ->assertCookie(Auth::guard('web')->getRecallerName());
    $this->assertAuthenticatedAs($user);
    $this->get(route($screen))->assertOk();
    $this->get('/')->assertRedirect(route('jobs.index'));
    expect(ImpersonationLog::count())->toBe(0);
})->with([UserRole::Owner, UserRole::Admin, UserRole::Technician]);

test('a platform admin selects a real workspace once and can open Members without support access', function () {
    $admin = User::factory()->superAdmin()->create();
    $company = Company::factory()->create();
    $this->actingAs($admin)->post(route('admin.companies.workspace', $company))->assertRedirect(route('dashboard'));
    $this->get(route('team.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.role.value', 'owner')->where('auth.company.id', $company->id)->where('impersonation', null));
    expect($admin->fresh()->current_company_id)->toBe($company->id)
        ->and(ImpersonationLog::count())->toBe(0);
    $this->post(route('admin.companies.workspace', $company))->assertRedirect();
    expect($admin->memberships()->count())->toBe(1);
    $this->get(route('admin.companies.index'))->assertOk();
});

test('ordinary accounts cannot self-grant a company workspace and suspended workspaces stay blocked', function () {
    $user = memberOf();
    $company = Company::factory()->create();
    $this->actingAs($user)->post(route('admin.companies.workspace', $company))->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->post(route('admin.companies.workspace', Company::factory()->suspended()->create()))->assertForbidden();
});

test('an office admin can open Members and manage technicians without promoting themselves or editing owners', function () {
    $company = Company::factory()->create();
    $admin = memberOf($company, UserRole::Admin);
    $owner = memberOf($company);
    $tech = memberOf($company, UserRole::Technician);
    $this->actingAs($admin)->get(route('team.index'))->assertOk();
    $member = $tech->membershipFor($company);
    $this->put(route('team.update', $member), ['role' => 'owner', 'is_active' => true])->assertSessionHasErrors('role');
    $this->put(route('team.update', $owner->membershipFor($company)), ['role' => 'technician', 'is_active' => true])->assertForbidden();
    $this->put(route('team.update', $member), ['role' => 'technician', 'is_active' => false])->assertSessionHasNoErrors();
    $this->actingAs($tech)->get(route('team.index'))->assertForbidden();
});

test('booking opens directly with the selected calendar date and ordinary job creation remains available', function () {
    $office = memberOf();
    $tech = memberOf($office->accessibleCompanies()->first(), UserRole::Technician);
    $this->actingAs($office)->get(route('jobs.create', ['book' => 1, 'date' => '2026-10-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('booking', true)->where('bookingDate', '2026-10-12')->where('auth.can.createJobs', true));
    $this->get(route('jobs.create', ['date' => 'bad-date']))->assertSessionHasErrors('date');
    $this->actingAs($tech)->get(route('jobs.create'))->assertForbidden();
});

test('timezone choices show offsets cities and countries while keeping IANA identifiers', function () {
    $options = collect(Timezones::options())->keyBy('value');
    expect($options['America/Vancouver']['label'])->toMatch('/^UTC-0[78]:00/')->toContain('Vancouver, Canada')
        ->and($options['Asia/Kolkata']['label'])->toContain('UTC+05:30', 'Kolkata, India')
        ->and($options['UTC']['label'])->toContain('UTC+00:00');
});
