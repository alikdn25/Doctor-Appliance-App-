<?php

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\MemberInvited;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->superAdmin()->create();
});

test('company members cannot open the super-admin panel', function (UserRole $role) {
    $this->actingAs(memberOf(role: $role))
        ->get(route('admin.companies.index'))
        ->assertForbidden();
})->with([UserRole::Owner, UserRole::Admin, UserRole::Technician]);

test('super-admins choose their own workspace separately from the platform panel', function () {
    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('workspaces.index'));
});

test('super-admins see all companies with status, plan and usage', function () {
    $a = Company::factory()->create(['name' => 'Alpha', 'plan' => 'pro']);
    $b = Company::factory()->suspended()->create(['name' => 'Beta']);
    memberOf($a);
    memberOf($a, UserRole::Technician);
    Brand::factory()->count(2)->create(['company_id' => $a->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.companies.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/companies/index')
            ->has('companies.data', 2)
            ->where('companies.data.0.name', 'Alpha')
            ->where('companies.data.0.plan', 'pro')
            ->where('companies.data.0.brands_count', 2)
            ->where('companies.data.0.members_count', 2)
            ->where('companies.data.1.status', 'suspended'));

    $this->get(route('admin.companies.index', ['search' => 'bet']))
        ->assertInertia(fn (Assert $page) => $page->has('companies.data', 1)->where('companies.data.0.name', 'Beta'));
});

test('super-admins can create a company with its owner', function () {
    Notification::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.companies.store'), [
            'name' => 'Coastal Repair',
            'country' => 'CA',
            'timezone' => 'America/Vancouver',
            'currency' => 'CAD',
            'plan' => 'starter',
            'subscription_status' => 'trialing',
            'owner_name' => 'Chris',
            'owner_email' => 'chris@example.com',
        ])
        ->assertRedirect();

    $company = Company::where('name', 'Coastal Repair')->sole();
    $owner = User::where('email', 'chris@example.com')->sole();

    expect($company->slug)->toBe('coastal-repair')
        ->and($company->business_hours)->toHaveKeys(Company::WEEKDAYS)
        ->and(Membership::withoutCompanyScope()->where('company_id', $company->id)->sole())
        ->user_id->toBe($owner->id)
        ->role->toBe(UserRole::Owner)
        ->and(AuditLog::where('action', 'company.created')->where('company_id', $company->id)->exists())->toBeTrue();

    Notification::assertSentTo($owner, MemberInvited::class);
});

test('super-admins can suspend a company, which is audited', function () {
    $company = Company::factory()->create();
    $member = memberOf($company);

    $this->actingAs($this->admin)
        ->put(route('admin.companies.update', $company), [
            'name' => $company->name,
            'status' => 'suspended',
            'plan' => 'pro',
            'subscription_status' => 'past_due',
        ])
        ->assertRedirect(route('admin.companies.show', $company));

    expect($company->fresh()->status)->toBe(CompanyStatus::Suspended);

    $log = AuditLog::where('action', 'company.status_changed')->sole();
    expect($log->changes['after']['status'])->toBe('suspended')
        ->and($log->changes['before']['status'])->toBe('active');

    $this->actingAs($member)->get(route('dashboard'))->assertForbidden();
});

test('the company page shows members, impersonations and audit log', function () {
    $company = Company::factory()->create();
    memberOf($company, UserRole::Owner, ['name' => 'Olive Owner']);

    $this->actingAs($this->admin)
        ->get(route('admin.companies.show', $company))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/companies/show')
            ->where('members.0.name', 'Olive Owner')
            ->has('impersonations', 0)
            ->has('auditLogs'));
});

test('an administrator can give a new owner usable access without an email provider', function () {
    config(['auth.email_delivery_enabled' => false]);
    Notification::fake();
    $this->actingAs($this->admin)->get(route('admin.companies.create'))->assertInertia(fn (Assert $page) => $page->where('emailAvailable', false));
    $data = [
        'name' => 'Manual Company', 'country' => 'CA', 'currency' => 'CAD',
        'owner_name' => 'Manual Owner', 'owner_email' => 'manual-owner@example.com',
    ];
    $this->post(route('admin.companies.store'), $data)->assertSessionHasErrors('owner_password');
    $this->post(route('admin.companies.store'), [...$data,
        'owner_password' => 'Owner-password-123!', 'owner_password_confirmation' => 'Owner-password-123!',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $owner = User::where('email', $data['owner_email'])->sole();
    expect(Hash::check('Owner-password-123!', $owner->password))->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeFalse();
    Notification::assertNothingSent();
});

test('new owner passwords are not stored in validation old input', function () {
    config(['auth.email_delivery_enabled' => false]);
    $this->actingAs($this->admin)->post(route('admin.companies.store'), [
        'owner_password' => 'Private-password-123!', 'owner_password_confirmation' => 'Private-password-123!',
    ])->assertSessionHasErrors('name')->assertSessionMissing('_old_input.owner_password')->assertSessionMissing('_old_input.owner_password_confirmation');
});
