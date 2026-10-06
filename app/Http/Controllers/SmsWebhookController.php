<?php

namespace App\Http\Controllers;

use App\Enums\MessageKind;
use App\Models\Company;
use App\Models\CustomerPhone;
use App\Models\Message;
use App\Sms\SmsProvider;
use App\Sms\SmsProviders;
use App\Sms\Telnyx\TelnyxProvider;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhooks of the platform's SMS provider: incoming texts from customers (stored on their timeline; STOP / START
 * keywords switch texting off and on for that number) and delivery status of our texts.
 * No session or CSRF; the provider's signature is checked with the company's subaccount credentials.
 */
class SmsWebhookController extends Controller
{
    public function __construct(private readonly SmsProviders $providers, private readonly CurrentCompany $tenancy) {}

    public function inbound(Request $request, string $provider): Response
    {
        [$sms, $company] = $this->verified($request, $provider);

        // Telnyx sends every event of the profile here; only incoming texts are stored.
        if ($sms instanceof TelnyxProvider && ! $sms->isInboundText($request)) {
            return response()->noContent();
        }

        $text = $sms->inbound($request);

        $this->tenancy->runAs($company, function () use ($text) {
            $from = PhoneNumber::normalize($text['from']);
            $phones = CustomerPhone::query()->where('number_normalized', $from)->get();
            $keyword = strtoupper(trim($text['body']));

            if (in_array($keyword, (array) config('sms.opt_out_keywords'), true)) {
                $phones->each(fn (CustomerPhone $phone) => $phone->forceFill(['sms_opted_out_at' => now()])->save());
            } elseif (in_array($keyword, (array) config('sms.opt_in_keywords'), true)) {
                $phones->each(fn (CustomerPhone $phone) => $phone->forceFill(['sms_opted_out_at' => null])->save());
            }

            // Delivered twice by the provider → stored once.
            if (Message::query()->where('provider_message_id', $text['message_id'])->exists()) {
                return;
            }

            $phone = $phones->first();
            $job = $phone
                ? Message::query()->where('customer_id', $phone->customer_id)->whereNotNull('service_job_id')->latest('id')->value('service_job_id')
                : null;

            Message::query()->create([
                'customer_id' => $phone?->customer_id,
                'service_job_id' => $job,
                'direction' => Message::INBOUND,
                'channel' => Message::SMS,
                'kind' => MessageKind::Reply,
                'from' => $from,
                'to' => PhoneNumber::normalize($text['to']),
                'body' => $text['body'],
                'status' => 'received',
                'provider_message_id' => $text['message_id'],
                'sent_at' => now(),
            ]);
        });

        // No automatic reply from us (the provider answers STOP/HELP itself). Twilio expects (empty) TwiML.
        return $sms->key() === 'twilio'
            ? response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml'])
            : response()->noContent();
    }

    public function status(Request $request, string $provider): Response
    {
        [$sms, $company] = $this->verified($request, $provider);
        $update = $sms->statusUpdate($request);

        $this->tenancy->runAs($company, function () use ($update) {
            $message = Message::query()->where('provider_message_id', $update['message_id'])->first();

            // Never move a message back from delivered to sent (callbacks can arrive out of order).
            if ($message !== null && $message->status !== 'delivered') {
                $message->update([
                    'status' => $update['status'],
                    'status_reason' => $update['error'] ? __('messages.blocked.provider', ['error' => $update['error']]) : $message->status_reason,
                ]);
            }
        });

        return response()->noContent();
    }

    /**
     * The provider named in the webhook URL, after its signature check with the company's account.
     *
     * @return array{0: SmsProvider, 1: Company}
     */
    private function verified(Request $request, string $provider): array
    {
        abort_unless($this->providers->has($provider), 404);
        $sms = $this->providers->get($provider);

        $account = $sms->accountForWebhook($request) ?? abort(404);
        abort_unless($sms->verifyWebhook($request, $account), 403);

        $company = Company::query()->find($account->company_id) ?? abort(404);

        return [$sms, $company];
    }
}
