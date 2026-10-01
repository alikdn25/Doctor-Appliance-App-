<?php

use App\Actions\Members\RemoveMember;
use App\Actions\Members\UpdateMember;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\AddedToCompany;
use App\Notifications\MemberInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->create(['name' => 'Doctor Appliance']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->actingAs($this->owner);
});

function membershipOf(User $user, Company $company): Membership
{
    return Membership::withoutCompanyScope()
        ->where('user_id', $user->id)
        ->where('company_id', $company->id)
        ->sole();
}

test('an owner can invite a new person', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);

    $this->post(route('team.store'), [
        'name' => 'Tom Tech',
        'email' => 'Tom@Example.com',
        'role' => 'technician',
        'brand_ids' => [$brand->id],
    ])->assertRedirect(route('team.index'));

    $user = User::where('email', 'tom@example.com')->sole();
    $membership = membershipOf($user, $this->company);

    expect($membership->role)->toBe(UserRole::Technician)
        ->and(DB::table('brand_user')->where('user_id', $user->id)->pluck('brand_id')->all())->toBe([$brand->id])
        ->and(AuditLog::where('action', 'member.added')->exists())->toBeTrue();

    Notification::assertSentTo($user, MemberInvited::class);
});

test('a person who already has an account is added without a new user', function () {
    $other = Company::factory()->create();
    $existing = memberOf($other, UserRole::Owner, ['email' => 'shared@example.com']);

    $this->post(route('team.store'), [
        'name' => 'Ignored Name',
        'email' => 'shared@example.com',
        'role' => 'admin',
    ])->assertRedirect();

    expect(User::where('email', 'shared@example.com')->count())->toBe(1)
        ->and($existing->fresh()->name)->not->toBe('Ignored Name')
        ->and(membershipOf($existing, $this->company)->role)->toBe(UserRole::Admin)
        ->and(membershipOf($existing, $other)->role)->toBe(UserRole::Owner);

    Notification::assertSentTo($existing, AddedToCompany::class);
    Notification::assertNotSentTo($existing, MemberInvited::class);
});

test('a person cannot be added twice', function () {
    $this->post(route('team.store'), [
        'name' => 'Me',
        'email' => $this->owner->email,
        'role' => 'admin',
    ])->assertSessionHasErrors('email');
});

test('super-admins cannot be added to a company', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->post(route('team.store'), [
        'name' => 'Admin',
        'email' => $admin->email,
        'role' => 'admin',
    ])->assertSessionHasErrors('email');
});

test('stage 2 roles cannot be assigned yet', function (string $role) {
    $this->post(route('team.store'), [
        'name' => 'Sub',
        'email' => 'sub@example.com',
        'role' => $role,
    ])->assertSessionHasErrors('role');
})->with(['subcontractor', 'collector']);

test('an owner can change role, active flag and brands of a member', function () {
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $tech = memberOf($this->company, UserRole::Technician);
    $membership = membershipOf($tech, $this->company);

    $this->put(route('team.update', $membership), [
        'role' => 'admin',
        'is_active' => false,
        'brand_ids' => [$brand->id],
    ])->assertRedirect(route('team.index'));

    expect($membership->fresh())
        ->role->toBe(UserRole::Admin)
        ->is_active->toBeFalse();

    $log = AuditLog::where('action', 'member.updated')->sole();
    expect($log->changes['after'])->toBe(['role' => 'admin', 'is_active' => false]);
});

test('owners cannot change or remove themselves', function () {
    $membership = membershipOf($this->owner, $this->company);

    $this->put(route('team.update', $membership), ['role' => 'technician', 'is_active' => true])->assertForbidden();
    $this->delete(route('team.destroy', $membership))->assertForbidden();
});

test('the last active owner cannot be demoted, deactivated or removed', function () {
    $membership = membershipOf($this->owner, $this->company);

    inCompany($this->company, function () use ($membership) {
        expect(fn () => app(UpdateMember::class)->handle($membership, UserRole::Admin, true, []))
            ->toThrow(ValidationException::class);
        expect(fn () => app(UpdateMember::class)->handle($membership, UserRole::Owner, false, []))
            ->toThrow(ValidationException::class);
        expect(fn () => app(RemoveMember::class)->handle($membership))
            ->toThrow(ValidationException::class);
    });

    expect($membership->fresh())->role->toBe(UserRole::Owner)->is_active->toBeTrue();
});

test('an owner can be demoted while another active owner remains', function () {
    $second = memberOf($this->company, UserRole::Owner);

    $this->put(route('team.update', membershipOf($second, $this->company)), ['role' => 'admin', 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect(membershipOf($second, $this->company)->role)->toBe(UserRole::Admin);
});

test('removing a member keeps the user account and their other companies', function () {
    $other = Company::factory()->create();
    $tech = memberOf($this->company, UserRole::Technician);
    Membership::factory()->create(['company_id' => $other->id, 'user_id' => $tech->id]);
    $membership = membershipOf($tech, $this->company);

    $this->delete(route('team.destroy', $membership))->assertRedirect(route('team.index'));

    expect(Membership::withoutCompanyScope()->whereKey($membership->id)->exists())->toBeFalse()
        ->and(User::find($tech->id))->not->toBeNull()
        ->and(membershipOf($tech, $other))->not->toBeNull()
        ->and(AuditLog::where('action', 'member.removed')->exists())->toBeTrue();
});

test('an invitation can be sent again until the person signs in', function () {
    $this->post(route('team.store'), ['name' => 'New', 'email' => 'new@example.com', 'role' => 'technician']);
    $user = User::where('email', 'new@example.com')->sole();
    $membership = membershipOf($user, $this->company);

    $this->post(route('team.resend-invitation', $membership))->assertRedirect();
    Notification::assertSentToTimes($user, MemberInvited::class, 2);

    $user->forceFill(['last_login_at' => now()])->save();
    $this->post(route('team.resend-invitation', $membership))->assertStatus(422);
});

test('the invitation link lets the new member set a password', function () {
    $this->post(route('team.store'), ['name' => 'New', 'email' => 'new@example.com', 'role' => 'technician']);
    $user = User::where('email', 'new@example.com')->sole();

    Notification::assertSentTo($user, MemberInvited::class, function (MemberInvited $notification) use ($user) {
        auth()->logout();

        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    $this->post(route('login.store'), ['email' => 'new@example.com', 'password' => 'new-secret-password']);
    $this->assertAuthenticatedAs($user);
});
