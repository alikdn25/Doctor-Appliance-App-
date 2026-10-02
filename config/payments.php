<?php

use App\Payments\Square\SquareProvider;

return [

    /*
    | Online payment providers companies can connect (SPEC §7.6). Each class implements
    | App\Payments\PaymentProvider. A company without a provider records payments manually;
    | manual payment methods are always available. Provider credentials come from .env (config/services.php).
    */
    'providers' => [
        SquareProvider::class,
    ],

    'square' => [
        /*
        | Countries where Square takes card payments for sellers. Companies elsewhere are not offered
        | Square; Stripe will be the next provider behind the same interface.
        */
        'countries' => ['US', 'CA', 'GB', 'IE', 'AU', 'JP', 'FR', 'ES'],

        // Permissions asked from the company's Square account.
        'scopes' => ['MERCHANT_PROFILE_READ', 'PAYMENTS_READ', 'PAYMENTS_WRITE', 'ORDERS_READ', 'ORDERS_WRITE'],

        // Access tokens last 30 days; they are refreshed when this close to expiry (and by the daily command).
        'refresh_before_days' => 7,
    ],

];
