<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ImpersonationLog;
use App\Models\Membership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
    $this->company = Company::factory()->create(['name' => 'Target Co']);
    $this->owner = memberOf($this->company, UserRole::Owner, ['name' => 'Tina Owner']);
});

test('a super-admin can log in as a company user and back, and it is logged', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]), ['reason' => 'Ticket #42'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->owner);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.company.name', 'Target Co')
            ->where('impersonation.userName', 'Tina Owner')
            ->where('impersonation.companyName', 'Target Co'));

    // Actions taken while impersonating record the super-admin.
    $this->post(route('taxes.store'), ['name' => 'GST', 'rate' => 5]);
    expect(AuditLog::where('action', 'tax_rate.created')->sole())
        ->user_id->toBe($this->owner->id)
        ->impersonator_id->toBe($this->admin->id);

    $this->delete(route('impersonation.stop'))
        ->assertRedirect(route('admin.companies.show', $this->company));

    $this->assertAuthenticatedAs($this->admin);

    $log = ImpersonationLog::sole();
    expect($log)
        ->super_admin_id->toBe($this->admin->id)
        ->impersonated_user_id->toBe($this->owner->id)
        ->company_id->toBe($this->company->id)
        ->reason->toBe('Ticket #42')
        ->ended_at->not->toBeNull();

    expect(AuditLog::whereIn('action', ['impersonation.started', 'impersonation.stopped'])->count())->toBe(2);
});

test('impersonating does not update the user\'s last login', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]));

    expect($this->owner->fresh()->last_login_at)->toBeNull();
});

test('two-factor enforcement is skipped while impersonating', function () {
    config(['fieldservice.require_two_factor' => true]);
    $admin = User::factory()->superAdmin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]));

    $this->get(route('dashboard'))->assertOk();
});

test('a super-admin can impersonate a member of a suspended company', function () {
    $this->company->update(['status' => 'suspended']);

    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]));

    $this->get(route('dashboard'))->assertOk();
});

test('the company cannot be switched while impersonating', function () {
    $other = Company::factory()->create();
    Membership::factory()->create(['company_id' => $other->id, 'user_id' => $this->owner->id]);

    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]));

    $this->post(route('companies.switch', $other))->assertForbidden();
});

test('only members of the company can be impersonated', function () {
    $outsider = memberOf();

    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $outsider]))
        ->assertNotFound();

    $this->assertAuthenticatedAs($this->admin);
    expect(ImpersonationLog::count())->toBe(0);
});

test('company users cannot impersonate', function () {
    $other = memberOf($this->company, UserRole::Technician);

    $this->actingAs($this->owner)
        ->post(route('admin.companies.impersonate', [$this->company, $other]))
        ->assertForbidden();
});

test('stopping without an active impersonation is refused', function () {
    $this->actingAs($this->owner)->delete(route('impersonation.stop'))->assertForbidden();
});
