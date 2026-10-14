<?php

return [
    'user_name' => 'Booking bot (:name)',
    'alert' => [
        'title' => 'New booking #:number from the website bot',
        'body' => ':customer · :date, :window · :problem',
    ],
    'errors' => [
        'unauthenticated' => 'Invalid or revoked token.',
        'company_unavailable' => 'This company cannot take bookings right now.',
        'too_many_requests' => 'Too many requests. Try again in a minute.',
        'too_many_bookings' => 'The booking limit for this hour is reached. The office will call the customer back.',
        'window_taken' => 'This arrival window is no longer free. Ask for another one.',
        'no_address' => 'The customer has no address. Add one first.',
        'no_brand' => 'The company has no brand to book under yet.',
    ],
    'settings' => [
        'title' => 'Booking bot',
        'hint' => 'API tokens for the booking assistant on your website. It can only see free arrival windows, find a customer by phone, add a customer and book a job. It cannot change or delete anything and never sees invoices, payments or reports. Every booking is in the change log marked "Booking bot", and you get a notification.',
        'name' => 'Name',
        'name_placeholder' => 'Website',
        'bookings_per_hour' => 'Bookings per hour',
        'requests_per_minute' => 'Requests per minute',
        'create' => 'Create token',
        'new_token' => 'Copy this token now. It is shown only once.',
        'copy' => 'Copy token',
        'copied' => 'Token copied.',
        'api_url' => 'API address',
        'empty' => 'No tokens yet.',
        'last_used' => 'Last used :date',
        'never_used' => 'Never used',
        'revoked' => 'Token revoked.',
        'revoked_on' => 'Revoked :date',
        'revoke' => 'Revoke',
        'revoke_confirm' => 'Revoke this token? The bot stops working until it gets a new one.',
        'limits' => ':bookings bookings per hour · :requests requests per minute',
    ],
];
