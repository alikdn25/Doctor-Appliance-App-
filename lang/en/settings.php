<?php

return [
    'heading' => 'Settings',
    'description' => 'Manage your profile and account settings',

    'nav' => [
        'profile' => 'Profile',
        'security' => 'Security',
        'appearance' => 'Appearance',
    ],

    'profile' => [
        'title' => 'Profile settings',
        'heading' => 'Profile',
        'description' => 'Update your name, email address and phone',
        'name' => 'Name',
        'phone' => 'Phone',
        'updated' => 'Profile updated.',
    ],

    'security' => [
        'title' => 'Security settings',
        'password_heading' => 'Update password',
        'password_description' => 'Ensure your account is using a long, random password to stay secure',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'password_updated' => 'Password updated.',
    ],

    'two_factor' => [
        'heading' => 'Two-factor authentication',
        'description' => 'Optional extra protection for your account. Enable it when you are ready.',
        'enabled_text' => 'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
        'disabled_text' => 'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
        'enable' => 'Enable 2FA',
        'disable' => 'Disable 2FA',
        'continue_setup' => 'Continue setup',
        'setup_title' => 'Enable two-factor authentication',
        'setup_description' => 'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
        'verify_title' => 'Verify authentication code',
        'verify_description' => 'Enter the 6-digit code from your authenticator app',
        'enabled_title' => 'Two-factor authentication enabled',
        'enabled_description' => 'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
        'manual_entry' => 'or, enter the code manually',
        'recovery_title' => '2FA recovery codes',
        'recovery_description' => 'Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.',
        'view_codes' => 'View recovery codes',
        'hide_codes' => 'Hide recovery codes',
        'regenerate' => 'Regenerate codes',
        'recovery_hint' => 'Each recovery code can be used once to access your account and will be removed after use. If you need more, click "Regenerate codes" above.',
    ],

    'appearance' => [
        'title' => 'Appearance settings',
        'description' => 'Update the appearance settings for your account',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],
];
