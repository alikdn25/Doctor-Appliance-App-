<?php

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
});

function registrationPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'New Owner', 'email' => 'new-owner@example.com',
        'password' => 'New-owner-password-123!', 'password_confirmation' => 'New-owner-password-123!',
    ], $overrides);
}

test('login offers public registration and registration explains the next steps', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
    $this->get(route('register'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/register'));
});

test('registration creates an unverified ordinary account and sends confirmation', function () {
    $this->post(route('register.store'), registrationPayload([
        'email' => 'NEW-OWNER@example.com', 'is_super_admin' => true,
        'email_verified_at' => now(), 'current_company_id' => Company::factory()->create()->id,
    ]))->assertSessionHasNoErrors()->assertRedirect();

    $user = User::where('email', 'new-owner@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->is_super_admin)->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->current_company_id)->toBeNull()
        ->and($user->memberships()->count())->toBe(0);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->get(route('onboarding.company.create'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/verify-email')->where('email', $user->email));
});

test('duplicate emails cannot replace an existing or deleted account', function (bool $deleted) {
    $existing = User::factory()->create(['email' => 'existing@example.com']);
    if ($deleted) {
        $existing->delete();
    }
    $this->post(route('register.store'), registrationPayload(['email' => 'EXISTING@example.com']))
        ->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(User::withTrashed()->where('email', 'existing@example.com')->count())->toBe(1);
})->with([false, true]);

test('registration validates the password confirmation and required fields', function () {
    $this->post(route('register.store'), registrationPayload(['name' => '', 'password_confirmation' => 'wrong']))
        ->assertSessionHasErrors(['name', 'password']);
    expect(User::count())->toBe(0);
});

test('invalid registration requests are rate limited', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('register.store'), [])->assertUnprocessable();
    }
    $this->postJson(route('register.store'), [])->assertTooManyRequests();
});

test('a signed confirmation activates only its authenticated account', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);
    $this->actingAs($user)->get($url)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get(route('dashboard'))->assertRedirect(route('onboarding.company.create'));
    expect(Membership::count())->toBe(0);
});

test('confirmation rejects expired links and links for another account', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);
    $foreign = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $other->id, 'hash' => sha1($other->email),
    ]);
    $this->actingAs($user)->get($expired)->assertForbidden();
    $this->get($foreign)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a changed email invalidates its earlier confirmation link', function () {
    $user = User::factory()->unverified()->create();
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id, 'hash' => sha1($user->email),
    ]);
    $user->update(['email' => 'changed@example.com']);
    $this->actingAs($user)->get($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('confirmation can be resent but repeated sends are throttled', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->post(route('verification.send'))->assertRedirect()->assertSessionHas('status', 'verification-link-sent');
    Notification::assertSentTo($user, VerifyEmail::class);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('verification.send'))->assertRedirect();
    }
    $this->post(route('verification.send'))->assertTooManyRequests();
});

test('unverified accounts cannot create or inspect company data', function () {
    $user = User::factory()->unverified()->memberOf()->create();
    $this->actingAs($user)->get(route('customers.index'))->assertRedirect(route('verification.notice'));
    $this->post(route('onboarding.company.store'), [])->assertRedirect(route('verification.notice'));
});

test('setting a password through a valid invitation or reset token also confirms the email', function () {
    $user = User::factory()->unverified()->create();
    $token = Illuminate\Support\Facades\Password::broker()->createToken($user);
    $this->post(route('password.update'), [
        'token' => $token, 'email' => $user->email,
        'password' => 'New-owner-password-123!', 'password_confirmation' => 'New-owner-password-123!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
