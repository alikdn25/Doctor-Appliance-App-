<?php

use App\Sms\Twilio\TwilioProvider;

return [

    /*
    | The platform's SMS provider (SPEC §7.7). Every company in "Automatic" SMS mode gets its own subaccount and
    | number under the platform's account. Credentials come from .env (config/services.php → twilio).
    */
    'provider' => TwilioProvider::class,

    /*
    | Visit reminders go out the day before, at this local hour of the company (24 h clock).
    */
    'reminder_hour' => (int) env('SMS_REMINDER_HOUR', 17),

    /*
    | Keywords handled for every number (carrier rules). Twilio also answers STOP/HELP automatically
    | (Advanced Opt-Out); we record the opt-out so nothing is sent to that customer again.
    */
    'opt_out_keywords' => ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE'],
    'opt_in_keywords' => ['START', 'UNSTOP', 'YES'],
    'help_keywords' => ['HELP', 'INFO'],

];
