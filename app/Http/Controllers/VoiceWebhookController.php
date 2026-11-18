<?php

namespace App\Http\Controllers;

use App\Messaging\SmsThreads;
use App\Models\Company;
use App\Models\SmsAccount;
use App\Sms\SmsProviders;
use App\Support\PhoneNumber;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Telnyx voice events for company numbers (owner's task of November 18): a call nobody answered gets the company's
 * missed-call text (Settings → Auto messages; once per 24 hours per number, business hours only, never to staff).
 * Off until TELNYX_VOICE_ENABLED is set and the number's calls go through a Telnyx voice app with this webhook.
 * Signature checked like the SMS webhooks; no session, no CSRF.
 */
class VoiceWebhookController extends Controller
{
    public function __invoke(Request $request, SmsProviders $providers, CurrentCompany $tenancy, SmsThreads $threads): Response
    {
        abort_unless(config('sms.missed_calls.enabled') && $providers->has('telnyx'), 404);

        $to = PhoneNumber::normalize((string) $request->input('data.payload.to'));
        $account = $to === '' ? null : SmsAccount::withoutCompanyScope()->where('provider', 'telnyx')->where('phone_number', $to)->first();
        abort_if($account === null, 404);
        abort_unless($providers->get('telnyx')->verifyWebhook($request, $account), 403);

        $missed = $request->input('data.event_type') === 'call.hangup'
            && in_array((string) $request->input('data.payload.hangup_cause'), (array) config('sms.missed_calls.causes'), true);
        $from = PhoneNumber::normalize((string) $request->input('data.payload.from'));
        $company = Company::query()->find($account->company_id);

        if ($missed && $from !== '' && $company !== null) {
            $tenancy->runAs($company, fn () => $threads->missedCall($from));
        }

        return response()->noContent();
    }
}
