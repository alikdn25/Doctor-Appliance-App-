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
        ->assertRedirect(route('jobs.mine', absolute: false));

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('owners and admins may use the app without enabling two-factor authentication', function (UserRole $role) {
    // A leftover production environment flag must not reinstate mandatory enrollment.
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(memberOf(role: $role))
        ->get(route('dashboard'))
        ->assertOk();
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

test('super-admins may use the platform without enabling two-factor authentication', function () {
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.companies.index'))
        ->assertOk();
});

test('personal settings stay reachable while two-factor is required', function () {
    config(['fieldservice.require_two_factor' => true]);

    $this->actingAs(memberOf())->get(route('profile.edit'))->assertOk();
});
