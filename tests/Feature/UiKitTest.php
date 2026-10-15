<?php

use Illuminate\Support\Facades\Route;

test('the UI kit page exists only in local development', function () {
    // The test environment is not "local": the route is not registered, so it can never reach production.
    expect(Route::has('dev.ui'))->toBeFalse();
    $this->get('/dev/ui')->assertNotFound();
});
