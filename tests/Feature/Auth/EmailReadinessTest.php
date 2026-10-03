<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\ImpersonationLog;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['auth.email_delivery_enabled' => false]);
    Notification::fake();
});

test('unverified company users can work before mail is connected without being marked verified', function (UserRole $role) {
    $user = User::factory()->unverified()->memberOf(role: $role)->create();
    $screen = $role === UserRole::Technician ? 'jobs.mine' : 'dashboard';
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true])
        ->assertSessionHasNoErrors()->assertRedirect(route($screen, absolute: false));
    $this->get(route($screen))->assertOk()->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
    $this->get(route('verification.notice'))->assertRedirect(route('dashboard'));
    expect($user->fresh()->email_verified_at)->toBeNull()
        ->and(ImpersonationLog::count())->toBe(0);
    Notification::assertNothingSent();
})->with([UserRole::Owner, UserRole::Admin, UserRole::Technician]);

test('registration without mail leads to a usable own company rather than confirmation', function () {
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page->where('confirmationRequired', false));
    $this->post(route('register.store'), [
        'name' => 'New Owner', 'email' => 'mail-off@example.com',
        'password' => 'New-owner-password-123!', 'password_confirmation' => 'New-owner-password-123!',
        'is_super_admin' => true, 'email_verified_at' => now(),
    ])->assertSessionHasNoErrors();
    $user = User::where('email', 'mail-off@example.com')->sole();
    $this->get(route('dashboard'))->assertRedirect(route('onboarding.company.create'));
    $this->get(route('onboarding.company.create'))->assertInertia(fn (Assert $page) => $page->where('confirmationRequired', false));
    $this->post(route('onboarding.company.store'), [
        'name' => 'Ready Company', 'country' => 'CA', 'vertical' => 'appliance_repair',
        'timezone' => 'America/Vancouver', 'currency' => 'CAD', 'locale' => 'en-CA',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();
    $company = $user->fresh()->accessibleCompanies()->sole();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($user->fresh()->is_super_admin)->toBeFalse()
        ->and($user->membershipFor($company)->role)->toBe(UserRole::Owner)
        ->and(Brand::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1);
    Notification::assertNothingSent();
});

test('mail readiness does not remove company isolation or administration permissions', function () {
    $user = User::factory()->unverified()->memberOf()->create();
    $foreign = Company::factory()->create();
    $this->actingAs($user)->post(route('companies.switch', $foreign))->assertForbidden();
    $this->post(route('admin.companies.workspace', $foreign))->assertForbidden();
    $this->get(route('admin.companies.show', $foreign))->assertForbidden();
    $this->get(route('dashboard'))->assertOk();
});

test('suspended company memberships remain blocked with mail off', function () {
    $company = Company::factory()->suspended()->create();
    $user = User::factory()->unverified()->memberOf($company)->create();
    $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
});

test('inactive accounts remain logged out with mail off', function () {
    $user = User::factory()->inactive()->unverified()->memberOf()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('profile email changes do not promise confirmation while mail is off', function () {
    $user = memberOf();
    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name, 'email' => 'changed-mail-off@example.com', 'phone' => '',
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
    expect($user->fresh()->email_verified_at)->toBeNull();
    $this->get(route('dashboard'))->assertOk();
    Notification::assertNothingSent();
});

test('password reset and resend do not claim to send unavailable mail', function () {
    $user = User::factory()->unverified()->create();
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('canResetPassword', false));
    $this->get(route('password.request'))->assertInertia(fn (Assert $page) => $page->where('emailAvailable', false));
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasErrors('email');
    $this->actingAs($user)->post(route('verification.send'))->assertSessionHasErrors('email');
    Notification::assertNothingSent();
});

test('enabling tested mail restores verification and sends the first link only once', function () {
    $user = User::factory()->unverified()->memberOf()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    config(['auth.email_delivery_enabled' => true]);
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertOk();
    $this->get(route('verification.notice'))->assertOk();
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($url)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get(route('dashboard'))->assertOk();
});

test('email verification is controlled on the server rather than by a request parameter', function () {
    config(['auth.email_delivery_enabled' => true]);
    $user = User::factory()->unverified()->memberOf()->create();
    $this->actingAs($user)->get(route('dashboard', ['email_delivery_enabled' => false, 'email_verification_required' => false]))
        ->assertRedirect(route('verification.notice'));
    config(['auth.email_verification_required' => false]);
    $this->get(route('dashboard'))->assertOk();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('support can inspect an unverified owner before mail is connected', function () {
    $admin = User::factory()->superAdmin()->create();
    $company = Company::factory()->create();
    $owner = User::factory()->unverified()->memberOf($company)->create();
    $this->actingAs($admin)->post(route('admin.companies.impersonate', [$company, $owner]))->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();
    expect($owner->fresh()->hasVerifiedEmail())->toBeFalse();
});
