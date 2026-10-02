<?php

return [

    /*
    | Online payment providers companies can pick in settings (SPEC §7.6). Each class implements
    | App\Payments\PaymentProvider. A company without a provider records payments manually;
    | manual payment methods are always available. Provider credentials come from .env.
    */
    'providers' => [
        // App\Payments\Square\SquareProvider::class (next task)
    ],

];
