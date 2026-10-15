<?php

use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Personal settings: available to every signed-in user (also super-admins and
// users without a company), so they stay outside the tenant middleware.
Route::middleware(['auth', 'active', 'tenant:optional'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // Notifications: the in-app list and push on this phone or browser.
    Route::get('settings/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('settings/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('settings/notifications/push', [NotificationController::class, 'subscribe'])->middleware('throttle:20,1')->name('notifications.push.subscribe');
    Route::delete('settings/notifications/push', [NotificationController::class, 'unsubscribe'])->name('notifications.push.unsubscribe');
});
