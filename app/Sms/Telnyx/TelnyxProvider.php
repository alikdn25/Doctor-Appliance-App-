<?php

namespace App\Sms\Telnyx;

use App\Models\Company;
use App\Models\SmsAccount;
use App\Models\SmsRegistration;
use App\Sms\SmsException;
use App\Sms\SmsProvider;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telnyx (API v2): one platform API key. Every company gets its own messaging profile (stored as account_sid) and a
 * local number ordered onto that profile. Incoming texts reach the profile's webhook; delivery status comes to the
 * per-message webhook. Webhooks are signed with Ed25519 (telnyx-signature-ed25519 over "timestamp|raw body") and
 * checked with the account's public key. US A2P 10DLC: brand and campaign IDs from the Telnyx portal.
 */
class TelnyxProvider implements SmsProvider
{
    /** Webhooks older than this are refused (replay protection). */
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function key(): string
    {
        return 'telnyx';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.telnyx.api_key')) && filled(config('services.telnyx.public_key'));
    }

    public function provision(Company $company): SmsAccount
    {
        if (! $this->isConfigured()) {
            throw new SmsException(__('messages.account.not_configured'));
        }

        return $this->tenancy->runAs($company, function () use ($company) {
            $account = SmsAccount::query()->first();

            if ($account === null) {
                $profile = $this->ok($this->api()->post('/v2/messaging_profiles', [
                    'name' => "{$company->name} (#{$company->id})",
                    // Texts only to numbers of the company's country.
                    'whitelisted_destinations' => [strtoupper($company->country)],
                    'webhook_url' => route('webhooks.sms.inbound', $this->key()),
                    'webhook_api_version' => '2',
                ]));

                $account = SmsAccount::query()->create([
                    'provider' => $this->key(),
                    'account_sid' => (string) data_get($profile, 'data.id'),
                    // Telnyx uses the platform API key; there is no per-company secret.
                    'auth_token' => '',
                ]);
            }

            if ($account->phone_number === null) {
                $available = $this->ok($this->api()->get('/v2/available_phone_numbers', [
                    'filter[country_code]' => strtoupper($company->country),
                    'filter[features][]' => 'sms',
                    'filter[phone_number_type]' => 'local',
                    'filter[limit]' => 1,
                ]));
                $number = data_get($available, 'data.0.phone_number');

                if (! is_string($number)) {
                    throw new SmsException(__('messages.account.failed', ['error' => 'no local number available']));
                }

                $order = $this->ok($this->api()->post('/v2/number_orders', [
                    'phone_numbers' => [['phone_number' => $number]],
                    'messaging_profile_id' => $account->account_sid,
                    'customer_reference' => "company-{$company->id}",
                ]));

                $account->update([
                    'phone_number' => $number,
                    'phone_number_sid' => data_get($order, 'data.phone_numbers.0.id') ?? data_get($order, 'data.id'),
                ]);
            }

            return $account;
        });
    }

    public function send(SmsAccount $account, string $to, string $body): string
    {
        $message = $this->ok($this->api()->post('/v2/messages', [
            'from' => $account->phone_number,
            'to' => $to,
            'text' => $body,
            'messaging_profile_id' => $account->account_sid,
            'webhook_url' => route('webhooks.sms.status', $this->key()),
        ]));

        return (string) data_get($message, 'data.id');
    }

    public function verifyWebhook(Request $request, SmsAccount $account): bool
    {
        $signature = base64_decode((string) $request->header('telnyx-signature-ed25519'), true);
        $timestamp = (string) $request->header('telnyx-timestamp');
        $publicKey = base64_decode((string) config('services.telnyx.public_key'), true);

        if ($signature === false || $signature === '' || ! ctype_digit($timestamp) || $publicKey === false
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $timestamp.'|'.$request->getContent(), $publicKey);
        } catch (Throwable) {
            return false;
        }
    }

    public function accountForWebhook(Request $request): ?SmsAccount
    {
        $profile = (string) $request->input('data.payload.messaging_profile_id');

        return $profile === '' ? null : SmsAccount::withoutCompanyScope()
            ->where('provider', $this->key())
            ->where('account_sid', $profile)
            ->first();
    }

    public function inbound(Request $request): array
    {
        return [
            'from' => (string) $request->input('data.payload.from.phone_number'),
            'to' => (string) $request->input('data.payload.to.0.phone_number'),
            'body' => (string) $request->input('data.payload.text'),
            'message_id' => (string) $request->input('data.payload.id'),
        ];
    }

    public function isInboundText(Request $request): bool
    {
        return $request->input('data.event_type') === 'message.received';
    }

    public function statusUpdate(Request $request): array
    {
        $status = (string) $request->input('data.payload.to.0.status');
        $error = $request->input('data.payload.errors.0');

        return [
            'message_id' => (string) $request->input('data.payload.id'),
            'status' => match ($status) {
                'delivered' => 'delivered',
                'sending_failed', 'delivery_failed' => 'failed',
                default => 'sent',
            },
            'error' => is_array($error)
                ? trim('Telnyx error '.($error['code'] ?? '').' '.($error['title'] ?? ''))
                : null,
        ];
    }

    public function registrationStatus(SmsRegistration $registration, SmsAccount $account): ?array
    {
        if (! $registration->campaign_sid) {
            return null;
        }

        $campaign = $this->ok($this->api()->get("/10dlc/campaign/{$registration->campaign_sid}"));
        $status = (string) ($campaign['campaignStatus'] ?? '');

        return match (true) {
            $status === 'MNO_PROVISIONED' => ['status' => SmsRegistration::APPROVED, 'reason' => null],
            in_array($status, ['TCR_FAILED', 'TELNYX_FAILED', 'MNO_REJECTED', 'MNO_PROVISIONING_FAILED', 'TCR_SUSPENDED', 'TCR_EXPIRED'], true) => [
                'status' => SmsRegistration::REJECTED,
                'reason' => $status.(isset($campaign['failureReasons']) ? ': '.json_encode($campaign['failureReasons']) : ''),
            ],
            default => null,
        };
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config('services.telnyx.base_url'))
            ->withToken((string) config('services.telnyx.api_key'))
            ->asJson()
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(Response $response): array
    {
        if ($response->failed()) {
            // Telnyx error code and title only; never the API key.
            $error = $response->json('errors.0');
            Log::warning('Telnyx API error', ['status' => $response->status(), 'code' => $error['code'] ?? null, 'title' => $error['title'] ?? null]);

            throw new SmsException((string) ($error['detail'] ?? $error['title'] ?? 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }
}
