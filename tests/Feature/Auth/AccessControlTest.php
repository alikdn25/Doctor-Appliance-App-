<?php

use App\Enums\UserRole;
use App\Models\User;

test('deactivated users cannot log in', function () {
    $user = User::factory()->memberOf()->inactive()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

test('deactivated users with an open session are logged out', function () {
    $user = User::factory()->memberOf()->create();
    $this->actingAs($user);
    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('login records the last login time and is case-insensitive on email', function () {
    $user = User::factory()->memberOf()->create(['email' => 'Person@Example.com']);

    expect($user->email)->toBe('person@example.com');

    $this->post(route('login.store'), ['email' => 'PERSON@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('owners and admins must enable two-factor authentication', function (UserRole $role) {
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(memberOf(role: $role))
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
})->with([UserRole::Owner, UserRole::Admin]);

test('owners with two-factor enabled get in', function () {
    config(['fieldservice.require_two_factor' => true]);

    $user = User::factory()->withTwoFactor()->memberOf()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('technicians are not forced to enable two-factor authentication', function () {
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(memberOf(role: UserRole::Technician))
        ->get(route('dashboard'))
        ->assertOk();
});

test('super-admins must enable two-factor authentication', function () {
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.companies.index'))
        ->assertRedirect(route('security.edit'));
});

test('personal settings stay reachable while two-factor is required', function () {
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(memberOf())->get(route('profile.edit'))->assertOk();
});
