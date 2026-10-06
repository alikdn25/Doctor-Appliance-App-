<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('the command sets a new password and the person can sign in again', function () {
    $user = memberOf(Company::factory()->create(['name' => 'Doctor Appliance']));

    $this->artisan('app:reset-password', ['email' => strtoupper($user->email)])
        ->expectsQuestion('New password', 'brand-new-password-1')
        ->expectsOutputToContain('Password updated')
        ->expectsOutputToContain('Doctor Appliance — Owner')
        ->assertSuccessful();

    expect(Hash::check('brand-new-password-1', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'user.password_reset_cli')->count())->toBe(1);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'brand-new-password-1'])
        ->assertRedirect(route('jobs.index', absolute: false));
    $this->assertAuthenticatedAs($user);
});

test('a switched-off or deleted account is switched on again and old devices are signed out', function () {
    $user = memberOf();
    $user->forceFill(['is_active' => false, 'remember_token' => 'old-token'])->save();
    $user->delete();

    $this->artisan('app:reset-password', ['email' => $user->email, '--generate' => true])
        ->expectsOutputToContain('Temporary password:')
        ->expectsOutputToContain('active again')
        ->assertSuccessful();

    $fresh = User::find($user->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->remember_token)->not->toBe('old-token');
});

test('company memberships are reported but not changed', function () {
    $company = Company::factory()->create();
    $user = memberOf($company, UserRole::Technician);
    $user->memberships()->update(['is_active' => false]);

    $this->artisan('app:reset-password', ['email' => $user->email, '--generate' => true])
        ->expectsOutputToContain('switched off in this company')
        ->assertSuccessful();

    expect($user->memberships()->first()->is_active)->toBeFalse();
});

test('two-factor sign-in is turned off only when asked', function () {
    $user = memberOf();
    $user->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();

    $this->artisan('app:reset-password', ['email' => $user->email, '--generate' => true])->assertSuccessful();
    expect($user->fresh()->two_factor_secret)->not->toBeNull();

    $this->artisan('app:reset-password', ['email' => $user->email, '--generate' => true, '--without-2fa' => true])->assertSuccessful();
    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('an unknown email or a weak password changes nothing', function () {
    $user = memberOf();
    $hash = $user->password;

    $this->artisan('app:reset-password', ['email' => 'nobody@example.com'])
        ->expectsOutputToContain('No account with the email nobody@example.com')
        ->assertFailed();

    $this->artisan('app:reset-password', ['email' => $user->email])
        ->expectsQuestion('New password', 'short')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($hash);
});
