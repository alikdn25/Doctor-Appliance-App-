<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\BrowserSmokeSeeder;

test('browser fixtures include confirmed owner two-factor authentication and separate tenants', function () {
    $this->seed(BrowserSmokeSeeder::class);
    expect(Company::count())->toBe(2)
        ->and(User::where('email', 'browser-owner@example.com')->sole()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('browser fixtures refuse to write outside the testing environment', function () {
    $this->app['env'] = 'production';
    try {
        (new BrowserSmokeSeeder)->run();
        test()->fail('Production browser seeding must fail.');
    } catch (LogicException $exception) {
        expect($exception->getMessage())->toContain('APP_ENV=testing');
    }
    expect(Company::count())->toBe(0)->and(User::count())->toBe(0);
});
