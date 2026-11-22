<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * The APK path is a top-level payments key: /download/android.apk, /download/android.json and /android read
 * config('payments.android_apk_path'), so it must not be nested under a provider.
 */
it('has a default Android APK path at the top level of the payments config', function () {
    $config = require base_path('config/payments.php');

    expect($config)->toHaveKey('android_apk_path')
        ->and($config['square'])->not->toHaveKey('android_apk_path')
        ->and((string) $config['android_apk_path'])->not->toBe('');
});
