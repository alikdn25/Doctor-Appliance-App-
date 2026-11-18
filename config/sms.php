<?php

use App\Sms\Telnyx\TelnyxProvider;
use App\Sms\Twilio\TwilioProvider;

return [

    /*
    | The platform's SMS provider for new numbers (SPEC §7.7): telnyx (default) or twilio. Every company in
    | "Automatic" SMS mode gets its own number under the platform's account; a company keeps the provider its number
    | was taken from. Credentials come from .env (config/services.php → telnyx / twilio).
    */
    'provider' => env('SMS_PROVIDER', 'telnyx'),

    'providers' => [
        'telnyx' => TelnyxProvider::class,
        'twilio' => TwilioProvider::class,
    ],

    /*
    | Visit reminders go out the day before, at this local hour of the company (24 h clock).
    */
    'reminder_hour' => (int) env('SMS_REMINDER_HOUR', 17),

    /*
    | Keywords handled for every number (carrier rules). Telnyx and Twilio also answer STOP/HELP automatically;
    | we record the opt-out so nothing is sent to that customer again.
    */
    'opt_out_keywords' => ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE'],
    'opt_in_keywords' => ['START', 'UNSTOP', 'YES'],
    'help_keywords' => ['HELP', 'INFO'],

    /*
    | Missed-call text (Settings → Auto messages): needs calls to the company number to go through Telnyx (a Call
    | Control / TeXML application on the number whose webhook is /webhooks/voice/telnyx). Off until that is set up.
    */
    'missed_calls' => [
        'enabled' => (bool) env('TELNYX_VOICE_ENABLED', false),
        // Telnyx hangup causes that mean nobody answered.
        'causes' => ['originator_cancel', 'timeout', 'no_answer', 'busy', 'user_busy', 'call_rejected', 'unanswered'],
    ],

];
