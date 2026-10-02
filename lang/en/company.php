<?php

return [
    'title' => 'Company settings',
    'description' => 'Time zone, currency, document numbering and business hours.',
    'saved' => 'Company settings saved.',
    'numbering' => 'Document numbering',
    'numbering_example' => 'Next invoice number: :example',
    'business_hours' => 'Business hours',
    'dispatch' => 'Scheduling',
    'travel_buffer_hint' => 'Time kept free after each visit to drive to the next one. The calendar warns when visits overlap.',
    'timezone_detected' => 'Time zone set to :timezone from your browser. You can change it in company settings.',
    'payments' => 'Payments',
    'payments_hint' => 'Cash, cheque, e-Transfer, your own card terminal and other payments can always be recorded by hand. An online provider adds card payments by link or QR code.',
    'no_payment_provider' => 'None — record payments by hand',
    'closed' => 'Closed',
    'opens' => 'Opens at',
    'closes' => 'Closes at',

    'fields' => [
        'name' => 'Company name',
        'timezone' => 'Time zone',
        'currency' => 'Currency',
        'invoice_prefix' => 'Invoice prefix',
        'estimate_prefix' => 'Estimate prefix',
        'next_number' => 'Next number',
        'travel_buffer_minutes' => 'Travel buffer (minutes)',
        'payment_provider' => 'Online payment provider',
    ],

    'weekdays' => [
        'mon' => 'Mon',
        'tue' => 'Tue',
        'wed' => 'Wed',
        'thu' => 'Thu',
        'fri' => 'Fri',
        'sat' => 'Sat',
        'sun' => 'Sun',
    ],
];
