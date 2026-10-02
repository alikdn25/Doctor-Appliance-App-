<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How a company texts its customers (SPEC §7.7).
 *
 * - Automatic: the platform's SMS provider (Twilio subaccount and number per company) sends everything.
 * - TechnicianPhone: buttons open the messages app on the technician's phone (sms: link) with the text ready;
 *   automated messages (reminders, review requests) go by email.
 * - Off: everything that would be an SMS goes by email.
 */
enum SmsMode: string
{
    use HasOptions;

    case Automatic = 'automatic';
    case TechnicianPhone = 'technician_phone';
    case Off = 'off';

    public function label(): string
    {
        return __("messages.modes.{$this->value}");
    }
}
