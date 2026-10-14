<?php

use App\Http\Controllers\Api\BookingBotController;
use Illuminate\Support\Facades\Route;

/*
 * Website booking bot (Claude through the API), one token per company: free windows, find a customer by phone,
 * add a customer, book a job. Nothing else: no changes, no deletes, no money, no reports. docs/BOOKING_BOT.md.
 */
Route::prefix('bot/v1')->middleware('bot')->group(function () {
    Route::get('windows', [BookingBotController::class, 'windows'])->name('bot.windows');
    Route::get('customers', [BookingBotController::class, 'findCustomer'])->name('bot.customers.find');
    Route::post('customers', [BookingBotController::class, 'storeCustomer'])->name('bot.customers.store');
    Route::post('bookings', [BookingBotController::class, 'book'])->name('bot.bookings.store');
});
