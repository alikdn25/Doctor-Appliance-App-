<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'two_factor_required' => 'Your role requires two-factor authentication. Please enable it to continue.',

    'fields' => [
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
    ],

    'login' => [
        'title' => 'Log in',
        'heading' => 'Log in to your account',
        'description' => 'Enter your email and password below to log in',
        'forgot' => 'Forgot your password?',
        'remember' => 'Remember me',
        'submit' => 'Log in',
    ],

    'forgot' => [
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'submit' => 'Email password reset link',
        'return_to' => 'Or, return to',
        'log_in' => 'log in',
    ],

    'reset' => [
        'title' => 'Reset password',
        'heading' => 'Set your password',
        'description' => 'Please enter your new password below',
        'submit' => 'Save password',
    ],

    'confirm' => [
        'title' => 'Confirm password',
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
        'submit' => 'Confirm password',
    ],

    'two_factor' => [
        'title' => 'Two-factor authentication',
        'code_title' => 'Authentication code',
        'code_description' => 'Enter the authentication code provided by your authenticator application.',
        'recovery_title' => 'Recovery code',
        'recovery_description' => 'Please confirm access to your account by entering one of your emergency recovery codes.',
        'recovery_placeholder' => 'Enter recovery code',
        'use_code' => 'log in using an authentication code',
        'use_recovery' => 'log in using a recovery code',
        'or_you_can' => 'or you can',
    ],

];
