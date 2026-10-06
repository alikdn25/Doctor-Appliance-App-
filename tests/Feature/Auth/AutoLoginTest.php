<?php

use App\Enums\UserRole;
use App\Models\User;

test('without the setting a visitor must sign in', function () {
    memberOf(role: UserRole::Owner);

    $this->get('/')->assertRedirect(route('login'));
    $this->get(route('jobs.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('with the setting every visitor goes straight to Jobs as that account', function () {
    $owner = memberOf(role: UserRole::Owner);
    $owner->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_confirmed_at' => now(),
    ])->save();
    config(['auth.auto_login_email' => ' '.strtoupper($owner->email).' ']);

    $this->get('/')->assertRedirect(route('jobs.index'));
    $this->assertAuthenticatedAs($owner);
    $this->get(route('jobs.index'))->assertOk();
    $this->get(route('login'))->assertRedirect('/');
});

test('an inactive or unknown account is never signed in automatically', function () {
    $owner = memberOf(role: UserRole::Owner);
    $owner->forceFill(['is_active' => false])->save();
    config(['auth.auto_login_email' => $owner->email]);

    $this->get('/')->assertRedirect(route('login'));
    $this->assertGuest();

    config(['auth.auto_login_email' => 'nobody@example.com']);
    $this->get('/')->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(1);
});
