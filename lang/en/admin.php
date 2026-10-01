<?php

return [
    'companies' => [
        'title' => 'Companies',
        'description' => ':count companies on the platform',
        'add' => 'New company',
        'add_description' => 'Creates the company and invites its Owner by email.',
        'create' => 'Create company',
        'search' => 'Search by name',
        'empty' => 'No companies found.',
        'usage' => ':brands brands · :members active members',
        'brands_count' => ':count brands',
        'owner' => 'Owner',
        'owner_hint' => 'If this email already has an account, that person is added as Owner.',
    ],

    'fields' => [
        'status' => 'Status',
        'plan' => 'Plan',
        'subscription_status' => 'Subscription status',
    ],

    'company_status' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
    ],

    'subscription_status' => [
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'cancelled' => 'Cancelled',
    ],

    'company_created' => 'Company created and Owner invited.',
    'company_updated' => 'Company saved.',
    'members' => 'Members',
    'last_login' => 'last sign-in :date',
    'never_logged_in' => 'never signed in',
    'impersonate' => 'Log in as',
    'impersonate_reason' => 'Reason for logging in as :name (saved in the log):',
    'impersonating' => 'You are logged in as :user (:company).',
    'stop_impersonating' => 'Return to admin',
    'impersonation_stopped' => 'You are back in the admin panel.',
    'impersonation_log' => 'Support log-ins',
    'impersonation_entry' => ':admin logged in as :user',
    'audit_log' => 'Audit log',
    'active' => 'active',
    'none' => 'Nothing yet.',
    'via' => 'via :name',
];
