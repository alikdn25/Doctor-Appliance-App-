<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

const TELNYX = 'https://api.telnyx.com';

/**
 * Telnyx (SPEC §7.7): platform API key, a messaging profile + number per company, Ed25519-signed webhooks.
 */
beforeEach(function () {
    $this->keys = sodium_crypto_sign_keypair();
    config([
        'sms.provider' => 'telnyx',
        'services.telnyx' => [
            'api_key' => 'KEYtest', 'base_url' => TELNYX,
            'public_key' => base64_encode(sodium_crypto_sign_publickey($this->keys)),
        ],
    ]);
    Http::fake([TELNYX.'/v2/messages' => Http::response(['data' => ['id' => 'tx-'.uniqid()]])]);
    Mail::fake();

    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'country' => 'CA']);
    $this->owner = memberOf($this->company, UserRole::Owner);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->create(['brand_id' => $this->brand->id]);
    $this->actingAs($this->owner);
});

function telnyxAccount(Company $company): SmsAccount
{
    return inCompany($company, fn () => SmsAccount::create([
        'provider' => 'telnyx', 'account_sid' => 'profile-1', 'auth_token' => '', 'phone_number' => '+16045550100',
    ]));
}

/** A webhook signed like Telnyx does: Ed25519 over "timestamp|raw body". */
function telnyxWebhook(string $url, array $payload, string $secretKey, ?int $timestamp = null)
{
    $body = json_encode($payload);
    $timestamp ??= time();
    $signature = base64_encode(sodium_crypto_sign_detached($timestamp.'|'.$body, $secretKey));

    return test()->call('POST', $url, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_TELNYX_SIGNATURE_ED25519' => $signature,
        'HTTP_TELNYX_TIMESTAMP' => (string) $timestamp,
    ], $body);
}

function inboundEvent(string $text, string $id = 'in-1', string $profile = 'profile-1'): array
{
    return ['data' => ['event_type' => 'message.received', 'id' => 'evt-'.$id, 'payload' => [
        'id' => $id, 'messaging_profile_id' => $profile, 'text' => $text,
        'from' => ['phone_number' => '+16045550142'], 'to' => [['phone_number' => '+16045550100']],
    ]]];
}

test('a company gets a messaging profile and a local number on it', function () {
    Http::fake([
        TELNYX.'/v2/messaging_profiles' => Http::response(['data' => ['id' => 'profile-new']]),
        TELNYX.'/v2/available_phone_numbers*' => Http::response(['data' => [['phone_number' => '+16045550199']]]),
        TELNYX.'/v2/number_orders' => Http::response(['data' => ['id' => 'order-1', 'phone_numbers' => [['id' => 'pn-1']]]]),
    ]);

    $this->post(route('company.messaging.provision'))->assertRedirect(route('company.messaging.edit'));

    $account = inCompany($this->company, fn () => SmsAccount::query()->sole());
    expect($account->provider)->toBe('telnyx')
        ->and($account->account_sid)->toBe('profile-new')
        ->and($account->phone_number)->toBe('+16045550199');
    Http::assertSent(fn (Request $r) => $r->url() === TELNYX.'/v2/messaging_profiles'
        && $r['whitelisted_destinations'] === ['CA'] && str_ends_with($r['webhook_url'], '/webhooks/sms/telnyx')
        && $r->hasHeader('Authorization', 'Bearer KEYtest'));
    Http::assertSent(fn (Request $r) => $r->url() === TELNYX.'/v2/number_orders'
        && $r['messaging_profile_id'] === 'profile-new' && $r['phone_numbers'] === [['phone_number' => '+16045550199']]);
});

test('a text goes out through Telnyx from the company number', function () {
    telnyxAccount($this->company);

    $this->post(route('jobs.sms', $this->job), ['body' => 'Running 10 minutes late.'])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === TELNYX.'/v2/messages'
        && $r['from'] === '+16045550100' && $r['to'] === '+16045550142' && $r['text'] === 'Running 10 minutes late.'
        && $r['messaging_profile_id'] === 'profile-1' && str_ends_with($r['webhook_url'], '/webhooks/sms/telnyx/status'));
    expect(messages_of($this->company)->last()->provider_message_id)->toStartWith('tx-');
});

test('a company that already has a Twilio number keeps texting through Twilio', function () {
    config(['services.twilio' => ['account_sid' => 'ACmaster', 'auth_token' => 'master', 'base_url' => 'https://api.twilio.com', 'messaging_url' => 'https://messaging.twilio.com']]);
    Http::fake(['https://api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    inCompany($this->company, fn () => SmsAccount::create([
        'provider' => 'twilio', 'account_sid' => 'ACsub', 'auth_token' => 'sub-token', 'phone_number' => '+16045550100',
    ]));

    $this->post(route('jobs.sms', $this->job), ['body' => 'Hello'])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/Accounts/ACsub/Messages.json'));
    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), TELNYX));
});

test('an incoming text with a valid signature lands on the customer and STOP opts out', function () {
    telnyxAccount($this->company);
    $secret = sodium_crypto_sign_secretkey($this->keys);

    telnyxWebhook('/webhooks/sms/telnyx', inboundEvent('Thanks, see you!'), $secret)->assertNoContent();
    telnyxWebhook('/webhooks/sms/telnyx', inboundEvent('Thanks, see you!'), $secret)->assertNoContent(); // retried
    telnyxWebhook('/webhooks/sms/telnyx', inboundEvent('STOP', 'in-2'), $secret)->assertNoContent();

    $messages = messages_of($this->company);
    expect($messages)->toHaveCount(2)
        ->and($messages->first()->body)->toBe('Thanks, see you!')
        ->and($messages->first()->customer_id)->toBe($this->customer->id)
        ->and(inCompany($this->company, fn () => CustomerPhone::query()->first()->sms_opted_out_at))->not->toBeNull();
});

test('forged, stale and unknown-profile webhooks are refused', function () {
    telnyxAccount($this->company);
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    $secret = sodium_crypto_sign_secretkey($this->keys);

    telnyxWebhook('/webhooks/sms/telnyx', inboundEvent('Hi'), $other)->assertForbidden();
    telnyxWebhook('/webhooks/sms/telnyx', inboundEvent('Hi'), $secret, time() - 3600)->assertForbidden();
    telnyxWebhook('/webhooks/sms/telnyx', inboundEvent('Hi', profile: 'someone-else'), $secret)->assertNotFound();
    $this->postJson('/webhooks/sms/telnyx', inboundEvent('Hi'))->assertForbidden();
    $this->postJson('/webhooks/sms/nobody', inboundEvent('Hi'))->assertNotFound();

    expect(messages_of($this->company))->toHaveCount(0);
});

test('delivery reports update the message and never step back from delivered', function () {
    telnyxAccount($this->company);
    $secret = sodium_crypto_sign_secretkey($this->keys);
    $this->post(route('jobs.sms', $this->job), ['body' => 'Hello']);
    $id = messages_of($this->company)->last()->provider_message_id;
    $event = fn (string $status, array $errors = []) => ['data' => ['event_type' => 'message.finalized', 'payload' => [
        'id' => $id, 'messaging_profile_id' => 'profile-1', 'to' => [['phone_number' => '+16045550142', 'status' => $status]], 'errors' => $errors,
    ]]];

    telnyxWebhook('/webhooks/sms/telnyx/status', $event('delivered'), $secret)->assertNoContent();
    telnyxWebhook('/webhooks/sms/telnyx/status', $event('sent'), $secret)->assertNoContent();
    expect(messages_of($this->company)->last()->status)->toBe('delivered');
});

test('a failed delivery keeps the carrier reason', function () {
    telnyxAccount($this->company);
    $secret = sodium_crypto_sign_secretkey($this->keys);
    $this->post(route('jobs.sms', $this->job), ['body' => 'Hello']);
    $id = messages_of($this->company)->last()->provider_message_id;

    telnyxWebhook('/webhooks/sms/telnyx/status', ['data' => ['event_type' => 'message.finalized', 'payload' => [
        'id' => $id, 'messaging_profile_id' => 'profile-1',
        'to' => [['phone_number' => '+16045550142', 'status' => 'delivery_failed']],
        'errors' => [['code' => '40300', 'title' => 'Blocked as spam']],
    ]]], $secret)->assertNoContent();

    $message = messages_of($this->company)->last();
    expect($message->status)->toBe('failed')->and($message->status_reason)->toContain('40300');
});

test('the 10DLC campaign status is followed at Telnyx', function (string $status, string $expected) {
    $account = telnyxAccount($this->company);
    inCompany($this->company, fn () => SmsRegistration::query()->create([
        'status' => SmsRegistration::SUBMITTED, 'campaign_sid' => 'CAMP1', 'business' => [],
    ]));
    Http::fake([TELNYX.'/10dlc/campaign/CAMP1' => Http::response(['campaignId' => 'CAMP1', 'campaignStatus' => $status])]);

    $this->artisan('sms:sync-registrations')->assertSuccessful();

    expect(inCompany($this->company, fn () => SmsRegistration::query()->sole()->status))->toBe($expected);
})->with([
    ['MNO_PROVISIONED', SmsRegistration::APPROVED],
    ['MNO_REJECTED', SmsRegistration::REJECTED],
    ['TCR_ACCEPTED', SmsRegistration::SUBMITTED],
]);

function messages_of(Company $company)
{
    return inCompany($company, fn () => Message::query()->orderBy('id')->get());
}
