<?php

beforeEach(function () {
    config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://app.example.com', 'session.secure' => true]);
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
