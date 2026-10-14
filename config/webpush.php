<?php

/*
 * Push notifications to phones and browsers (Web Push, VAPID). Generate the keys once with
 * php artisan webpush:keys and keep them: new keys make every phone turn notifications on again.
 */
return [
    'public_key' => env('WEBPUSH_PUBLIC_KEY'),
    'private_key' => env('WEBPUSH_PRIVATE_KEY'),
    // A way to reach the sender, required by push services.
    'subject' => env('WEBPUSH_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'hello@example.com')),
];
