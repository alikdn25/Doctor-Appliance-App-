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

test('stopping without an active impersonation changes nothing and just goes home', function () {
    $this->actingAs($this->owner)->delete(route('impersonation.stop'))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($this->owner);

    $this->actingAs($this->admin)->delete(route('impersonation.stop'))->assertRedirect(route('admin.companies.index'));
    $this->assertAuthenticatedAs($this->admin);
});

test('pressing Return to admin twice does not show an error', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]));

    $this->delete(route('impersonation.stop'))->assertRedirect(route('admin.companies.show', $this->company));
    $this->delete(route('impersonation.stop'))->assertRedirect(route('admin.companies.index'));

    $this->assertAuthenticatedAs($this->admin);
    expect(ImpersonationLog::sole()->ended_at)->not->toBeNull();
});

test('an admin page left open during support access explains how to get back instead of a 403', function () {
    $other = memberOf($this->company, UserRole::Technician);
    $this->actingAs($this->admin)
        ->post(route('admin.companies.impersonate', [$this->company, $this->owner]));

    // The old admin tab presses Support access for another member.
    $this->post(route('admin.companies.impersonate', [$this->company, $other]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast.message', __('admin.return_to_admin_first'));

    $this->assertAuthenticatedAs($this->owner);
    expect(ImpersonationLog::count())->toBe(1);
});

test('support access is not offered for platform admins who are company members', function () {
    $admin = User::factory()->superAdmin()->memberOf($this->company, UserRole::Owner)->create();

    $this->actingAs($admin)
        ->get(route('admin.companies.show', $this->company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->every(
                fn ($m) => $m['can_impersonate'] === ($m['user_id'] !== $admin->id),
            )));
});

test('company users still cannot open the admin panel', function () {
    $this->actingAs($this->owner)->get(route('admin.companies.index'))->assertForbidden();
});

test('support start records the target and real administrator rather than the administrator twice', function () {
    $this->actingAs($this->admin)->post(route('admin.companies.impersonate', [$this->company, $this->owner]));
    $log = AuditLog::where('action', 'impersonation.started')->sole();
    expect($log->user_id)->toBe($this->owner->id)->and($log->impersonator_id)->toBe($this->admin->id);
});

test('ordinary logout closes the support record and records its end', function () {
    $this->actingAs($this->admin)->post(route('admin.companies.impersonate', [$this->company, $this->owner]));
    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
    expect(ImpersonationLog::sole()->ended_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'impersonation.stopped')->count())->toBe(1);
});

test('admin support history distinguishes support access from direct sign-in with unambiguous timestamps', function () {
    $this->company->update(['timezone' => 'America/Vancouver', 'locale' => 'en-CA']);
    $support = ImpersonationLog::create([
        'company_id' => $this->company->id, 'super_admin_id' => $this->admin->id,
        'impersonated_user_id' => $this->owner->id, 'started_at' => '2026-10-03 15:22:12',
        'reason' => 'Historical note',
    ]);
    AuditLog::create([
        'company_id' => $this->company->id, 'user_id' => $this->admin->id,
        'impersonator_id' => $this->admin->id, 'action' => 'impersonation.started',
    ]);
    $this->actingAs($this->admin)->get(route('admin.companies.show', $this->company))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.timezone', 'America/Vancouver')
            ->where('company.timezone_label', fn (string $value) => str_contains($value, 'Vancouver, Canada'))
            ->where('company.locale', 'en-CA')
            ->where('members.0.last_login_at', null)
            ->where('members.0.last_support_at', '2026-10-03T15:22:12+00:00')
            ->where('impersonations.0.started_at', '2026-10-03T15:22:12+00:00')
            ->where('impersonations.0.ended_at', null)
            ->where('auditLogs.0.impersonator', null));
    expect($support->fresh()->ended_at)->toBeNull();
});
