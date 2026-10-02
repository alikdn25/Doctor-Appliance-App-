<?php

namespace App\Sms\Twilio;

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

/**
 * Twilio: subaccount + local number per company under the platform's master account (REST API 2010-04-01),
 * Messages API for sending, signed webhooks (X-Twilio-Signature, HMAC-SHA1 with the subaccount token) for
 * incoming texts and delivery status, Messaging API for the US A2P 10DLC brand/campaign status.
 */
class TwilioProvider implements SmsProvider
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function key(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.twilio.account_sid')) && filled(config('services.twilio.auth_token'));
    }

    public function provision(Company $company): SmsAccount
    {
        if (! $this->isConfigured()) {
            throw new SmsException(__('messages.account.not_configured'));
        }

        return $this->tenancy->runAs($company, function () use ($company) {
            $account = SmsAccount::query()->first();

            if ($account === null) {
                $sub = $this->ok($this->master()->post('/2010-04-01/Accounts.json', [
                    'FriendlyName' => "{$company->name} (#{$company->id})",
                ]));

                $account = SmsAccount::query()->create([
                    'provider' => $this->key(),
                    'account_sid' => $sub['sid'],
                    'auth_token' => $sub['auth_token'],
                ]);
            }

            if ($account->phone_number === null) {
                $available = $this->ok($this->as($account)->get(
                    "/2010-04-01/Accounts/{$account->account_sid}/AvailablePhoneNumbers/{$company->country}/Local.json",
                    ['SmsEnabled' => 'true', 'PageSize' => 1],
                ));
                $number = $available['available_phone_numbers'][0]['phone_number'] ?? null;

                if (! is_string($number)) {
                    throw new SmsException(__('messages.account.failed', ['error' => 'no local number available']));
                }

                $bought = $this->ok($this->as($account)->post("/2010-04-01/Accounts/{$account->account_sid}/IncomingPhoneNumbers.json", [
                    'PhoneNumber' => $number,
                    'SmsUrl' => route('webhooks.sms.inbound', $this->key()),
                    'SmsMethod' => 'POST',
                ]));

                $account->update(['phone_number' => $bought['phone_number'] ?? $number, 'phone_number_sid' => $bought['sid'] ?? null]);
            }

            return $account;
        });
    }

    public function send(SmsAccount $account, string $to, string $body): string
    {
        $message = $this->ok($this->as($account)->post("/2010-04-01/Accounts/{$account->account_sid}/Messages.json", [
            'To' => $to,
            'From' => $account->phone_number,
            'Body' => $body,
            'StatusCallback' => route('webhooks.sms.status', $this->key()),
        ]));

        return (string) $message['sid'];
    }

    public function verifyWebhook(Request $request, SmsAccount $account): bool
    {
        $signature = (string) $request->header('X-Twilio-Signature');

        if ($signature === '') {
            return false;
        }

        // Twilio signs the full URL plus every POST parameter (sorted by name) appended as name+value.
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $name => $value) {
            $data .= $name.(is_array($value) ? implode('', $value) : $value);
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $account->auth_token, true));

        return hash_equals($expected, $signature);
    }

    public function accountForWebhook(Request $request): ?SmsAccount
    {
        $sid = (string) $request->input('AccountSid');

        return $sid === '' ? null : SmsAccount::withoutCompanyScope()
            ->where('provider', $this->key())
            ->where('account_sid', $sid)
            ->first();
    }

    public function inbound(Request $request): array
    {
        return [
            'from' => (string) $request->input('From'),
            'to' => (string) $request->input('To'),
            'body' => (string) $request->input('Body'),
            'message_id' => (string) $request->input('MessageSid'),
        ];
    }

    public function statusUpdate(Request $request): array
    {
        $status = (string) $request->input('MessageStatus');

        return [
            'message_id' => (string) $request->input('MessageSid'),
            'status' => match ($status) {
                'delivered' => 'delivered',
                'failed', 'undelivered' => 'failed',
                default => 'sent',
            },
            'error' => $request->filled('ErrorCode') ? 'Twilio error '.$request->input('ErrorCode') : null,
        ];
    }

    public function registrationStatus(SmsRegistration $registration, SmsAccount $account): ?array
    {
        if ($registration->messaging_service_sid && $registration->campaign_sid) {
            $campaign = $this->ok($this->as($account, messaging: true)->get(
                "/v1/Services/{$registration->messaging_service_sid}/Compliance/Usa2p/{$registration->campaign_sid}",
            ));

            return match ($campaign['campaign_status'] ?? null) {
                'VERIFIED' => ['status' => SmsRegistration::APPROVED, 'reason' => null],
                'FAILED' => ['status' => SmsRegistration::REJECTED, 'reason' => $this->errors($campaign)],
                default => null,
            };
        }

        if ($registration->brand_registration_sid) {
            $brand = $this->ok($this->as($account, messaging: true)->get("/v1/a2p/BrandRegistrations/{$registration->brand_registration_sid}"));

            return ($brand['status'] ?? null) === 'FAILED'
                ? ['status' => SmsRegistration::REJECTED, 'reason' => $brand['failure_reason'] ?? $this->errors($brand)]
                : null;
        }

        return null;
    }

    private function master(): PendingRequest
    {
        return Http::baseUrl((string) config('services.twilio.base_url'))
            ->withBasicAuth((string) config('services.twilio.account_sid'), (string) config('services.twilio.auth_token'))
            ->asForm()
            ->acceptJson()
            ->timeout(20);
    }

    private function as(SmsAccount $account, bool $messaging = false): PendingRequest
    {
        return Http::baseUrl((string) config($messaging ? 'services.twilio.messaging_url' : 'services.twilio.base_url'))
            ->withBasicAuth($account->account_sid, $account->auth_token)
            ->asForm()
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(Response $response): array
    {
        if ($response->failed()) {
            // Twilio error code and message only; never credentials.
            Log::warning('Twilio API error', ['status' => $response->status(), 'code' => $response->json('code'), 'message' => $response->json('message')]);

            throw new SmsException((string) ($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function errors(array $resource): ?string
    {
        $errors = $resource['errors'] ?? null;

        return is_array($errors) && $errors !== [] ? json_encode($errors) : null;
    }
}
