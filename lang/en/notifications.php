<?php

return [
    'invited' => [
        'subject' => 'You have been invited to :company',
        'greeting' => 'Hello :name,',
        'line' => 'You have been added to :company. Set your password to get started.',
        'action' => 'Set password',
        'expires' => 'This link expires in :count minutes.',
    ],

    'added' => [
        'subject' => 'You now have access to :company',
        'greeting' => 'Hello :name,',
        'line' => 'You have been added to :company. Sign in with your existing account and switch to it from the company menu.',
        'action' => 'Sign in',
    ],

    'estimate_decided' => [
        'greeting' => 'Hello :name,',
        'action' => 'Open estimate',
        'action_job' => 'Open job',
    ],

    'estimate_approved' => [
        'subject' => 'Estimate :number approved by :customer',
        'line' => ':customer approved estimate :number (:total) online and signed it as :signer.',
        'deposit' => 'A deposit of :deposit was asked; it is shown on the estimate once paid.',
        'sms' => ':brand: :customer approved estimate :number (:total). :url',
    ],

    'estimate_declined' => [
        'subject' => 'Estimate :number declined by :customer',
        'line' => ':customer declined estimate :number (:total) online.',
        'reason' => 'Reason: :reason',
    ],
    'alert' => [
        'open' => 'Open',
    ],

    'page' => [
        'title' => 'Notifications',
        'description' => 'Bookings from the website bot, backup problems and other notices.',
        'empty' => 'No notifications yet.',
        'mark_read' => 'Mark all as read',
        'open' => 'Open',
        'push_title' => 'Notifications on this phone',
        'push_hint' => 'Get a notification on this phone or computer even when the app is closed.',
        'push_enable' => 'Turn on',
        'push_disable' => 'Turn off on this device',
        'push_on' => 'On for this device.',
        'push_blocked' => 'Notifications are blocked in this browser. Allow them in the browser or phone settings.',
        'push_unsupported' => 'This browser cannot show notifications. On iPhone, add the app to the Home Screen first.',
        'push_unavailable' => 'Push notifications are not set up on the server yet.',
        'push_failed' => 'Notifications could not be turned on. Try again.',
        'devices' => '{0} No devices|{1} 1 device|[2,*] :count devices',
    ],
];
