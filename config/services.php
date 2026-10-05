<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Square (SPEC §7.6): one Square application for the platform; each company authorizes it for its own
    | Square account by OAuth. Use SQUARE_ENVIRONMENT=sandbox with sandbox credentials for testing.
    | Webhook: subscribe the application to payment.created, payment.updated, refund.created, refund.updated
    | and oauth.authorization.revoked
    | with the URL in SQUARE_WEBHOOK_URL (exactly as entered in the Square dashboard; it is part of the signature).
    */
    // Google Places address suggestions in the browser (Places API (New) + Maps JavaScript API). A browser key:
    // restrict it to your domain (HTTP referrers) and to those two APIs in Google Cloud. Empty = manual address entry.
    'google_maps' => [
        'map_id' => env('GOOGLE_MAPS_MAP_ID', 'DEMO_MAP_ID'),
        'browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
        // Geocoding API key restricted to the server's IP: map positions for addresses typed by hand.
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
    ],

    'square' => [
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'application_secret' => env('SQUARE_APPLICATION_SECRET'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'webhook_url' => env('SQUARE_WEBHOOK_URL'),
        'api_version' => env('SQUARE_API_VERSION', '2025-10-16'),
    ],

    /*
    | Twilio (SPEC §7.7): the platform's master account. Companies get subaccounts and numbers under it.
    | Use Twilio test credentials (TWILIO_ACCOUNT_SID/TWILIO_AUTH_TOKEN of the test account) for tests.
    | Webhook URLs set on each number: {APP_URL}/webhooks/sms/twilio (incoming) and …/status (delivery).
    */
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'base_url' => env('TWILIO_BASE_URL', 'https://api.twilio.com'),
        'messaging_url' => env('TWILIO_MESSAGING_URL', 'https://messaging.twilio.com'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
