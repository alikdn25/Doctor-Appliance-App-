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
];
