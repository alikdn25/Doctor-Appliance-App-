<?php

beforeEach(function () {
    config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://app.example.com', 'session.secure' => true, 'mail.default' => 'smtp']);
});

test('deployment checks pass with configured production settings and current migrations', function () {
    $this->artisan('app:deployment-check')->assertSuccessful();
});

test('deployment checks reject debug mode and insecure URLs without printing secrets', function () {
    config(['app.debug' => true, 'app.url' => 'http://localhost', 'app.key' => 'secret-key-never-print']);
    $this->artisan('app:deployment-check', ['--before-migrate' => true])
        ->expectsOutput('FAIL APP_DEBUG=false')
        ->expectsOutput('FAIL APP_URL is an HTTPS domain')
        ->doesntExpectOutput('secret-key-never-print')
        ->assertFailed();
});

test('deployment checks require a configured encryption key and secure cookies', function () {
    config(['app.key' => null, 'session.secure' => false]);
    $this->artisan('app:deployment-check', ['--before-migrate' => true])
        ->expectsOutput('FAIL APP_KEY configured')->expectsOutput('FAIL Secure session cookie')->assertFailed();
});

test('deployment checks fail in production when email uses a test transport', function (string $mailer) {
    config(['mail.default' => $mailer]);
    $this->artisan('app:deployment-check', ['--before-migrate' => true])
        ->expectsOutput('FAIL Real email transport (MAIL_MAILER is not log/array)')
        ->expectsOutput('Email uses a test transport; customer emails will not be delivered.')
        ->assertFailed();
})->with(['log', 'array']);

test('deployment checks pass a real mailer without the test-transport note', function () {
    $this->artisan('app:deployment-check', ['--before-migrate' => true])
        ->expectsOutput('PASS Real email transport (MAIL_MAILER is not log/array)')
        ->doesntExpectOutput('Email uses a test transport; customer emails will not be delivered.')
        ->assertSuccessful();
});

test('outside production a test transport is only a note', function () {
    config(['app.env' => 'staging', 'mail.default' => 'log']);
    $this->artisan('app:deployment-check', ['--before-migrate' => true])
        ->doesntExpectOutput('PASS Real email transport (MAIL_MAILER is not log/array)')
        ->doesntExpectOutput('FAIL Real email transport (MAIL_MAILER is not log/array)')
        ->expectsOutput('Email uses a test transport; customer emails will not be delivered.');
});
