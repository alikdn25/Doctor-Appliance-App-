<?php

namespace App\Support;

class AccountEmail
{
    /** Enabled by the operator only after a real delivery test, not inferred from SMTP settings. */
    public static function deliveryEnabled(): bool
    {
        return (bool) config('auth.email_delivery_enabled');
    }

    public static function verificationRequired(): bool
    {
        return self::deliveryEnabled() && (bool) config('auth.email_verification_required');
    }
}
