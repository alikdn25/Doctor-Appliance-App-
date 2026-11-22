<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\FcmToken;
use App\Notifications\AppAlert;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * Push in the Android app (owner's task of November 22): Firebase Cloud Messaging HTTP v1 with the service account
 * from .env. Every push the browser gets also goes to each app install of the person, once.
 */

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config(['services.fcm.credentials' => base64_encode(json_encode([
        'type' => 'service_account', 'project_id' => 'doctor-appliance-test', 'client_email' => 'push@doctor-appliance-test.iam.gserviceaccount.com',
        'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token',
    ]))]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/doctor-appliance-test/messages/1']),
    ]);

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->actingAs($this->owner);
});

function fcmSends(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://fcm.googleapis.com/'))
        ->values()->all();
}

test('the app registers its token; the same install moves to the person signed in', function () {
    $this->postJson(route('notifications.fcm.store'), ['token' => str_repeat('a', 40)])->assertOk();
    $this->postJson(route('notifications.fcm.store'), ['token' => str_repeat('a', 40)])->assertOk();
    expect(FcmToken::query()->count())->toBe(1)->and(FcmToken::query()->sole()->user_id)->toBe($this->owner->id);

    $tech = memberOf($this->company, UserRole::Technician);
    $this->actingAs($tech)->postJson(route('notifications.fcm.store'), ['token' => str_repeat('a', 40)])->assertOk();
    expect(FcmToken::query()->sole()->user_id)->toBe($tech->id);

    $this->actingAs($tech)->deleteJson(route('notifications.fcm.destroy'), ['token' => str_repeat('a', 40)])->assertOk();
    expect(FcmToken::query()->count())->toBe(0);
});

test('a push goes to the Android token with the page to open and the buttons (Invoice paid → Paid screen)', function () {
    $this->postJson(route('notifications.fcm.store'), ['token' => 'android-token-'.str_repeat('x', 30)])->assertOk();

    $this->owner->notify(new AppAlert(
        'invoice_paid_online', 'Invoice INV-7 paid — $168.00', 'Jane Cooper paid online. Tap to send the receipt.',
        'https://app.example.test/payments/5/paid', mail: false,
        actions: [['action' => 'later', 'title' => 'Later']], actionUrl: 'https://app.example.test/push-actions/abc/__action__',
    ));

    $sends = fcmSends();
    expect($sends)->toHaveCount(1);
    $message = json_decode($sends[0]->body(), true)['message'];
    expect($sends[0]->url())->toBe('https://fcm.googleapis.com/v1/projects/doctor-appliance-test/messages:send')
        ->and($sends[0]->hasHeader('Authorization', 'Bearer ya29.test'))->toBeTrue()
        ->and($message['token'])->toBe('android-token-'.str_repeat('x', 30))
        ->and($message['data']['title'])->toBe('Invoice INV-7 paid — $168.00')
        ->and($message['data']['url'])->toBe('https://app.example.test/payments/5/paid')
        ->and(json_decode($message['data']['actions'], true))->toBe([['action' => 'later', 'title' => 'Later']])
        ->and($message['data']['action_url'])->toBe('https://app.example.test/push-actions/abc/__action__')
        // Data only: the app shows it itself (with its buttons), every value a string.
        ->and($message)->not->toHaveKey('notification')
        ->and(collect($message['data'])->every(fn ($v) => is_string($v)))->toBeTrue();
});

test('the online "Invoice paid" push reaches the app and opens the Paid screen', function () {
    $this->postJson(route('notifications.fcm.store'), ['token' => 'android-token-'.str_repeat('p', 30)])->assertOk();
    $alert = new AppAlert('invoice_paid_online', 'Invoice INV-1 paid — $10.00', 'Paid online.', route('payments.paid', 42), mail: false);

    expect($alert->via($this->owner))->toContain(FcmChannel::class);
    $this->owner->notify($alert);

    expect(json_decode(fcmSends()[0]->body(), true)['message']['data']['url'])->toBe(route('payments.paid', 42));
});

test('each install gets the push once; a removed install is forgotten', function () {
    $this->postJson(route('notifications.fcm.store'), ['token' => 'phone-one-'.str_repeat('1', 30)])->assertOk();
    $this->postJson(route('notifications.fcm.store'), ['token' => 'phone-two-'.str_repeat('2', 30)])->assertOk();
    Http::swap(new HttpFactory);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test']),
        'https://fcm.googleapis.com/*' => Http::sequence()
            ->push(['name' => 'ok'])
            ->push(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    $this->owner->notify(new AppAlert('test', 'Hello', 'Body', url('/'), mail: false));

    expect(fcmSends())->toHaveCount(2)
        ->and(FcmToken::query()->pluck('token')->all())->toBe(['phone-one-'.str_repeat('1', 30)]);
});

test('without the Firebase key the channel stays quiet', function () {
    config(['services.fcm.credentials' => null]);
    $this->postJson(route('notifications.fcm.store'), ['token' => 'android-token-'.str_repeat('q', 30)])->assertOk();

    $this->owner->notify(new AppAlert('test', 'Hello', 'Body', url('/'), mail: false));

    expect(fcmSends())->toHaveCount(0)->and(FcmChannel::configured())->toBeFalse();
});
