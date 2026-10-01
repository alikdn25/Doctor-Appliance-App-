<?php

use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Company\BrandController;
use App\Http\Controllers\Company\CompanySettingsController;
use App\Http\Controllers\Company\SwitchCompanyController;
use App\Http\Controllers\Company\TaxRateController;
use App\Http\Controllers\Company\TeamController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active'])->group(function () {

    // Tenant area: everything here runs inside the current company.
    Route::middleware(['tenant', 'two-factor'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::post('companies/{company}/switch', SwitchCompanyController::class)->name('companies.switch');

        Route::prefix('company')->group(function () {
            Route::get('settings', [CompanySettingsController::class, 'edit'])->name('company.settings.edit');
            Route::put('settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');

            Route::resource('brands', BrandController::class)->except('show');

            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::post('team', [TeamController::class, 'store'])->name('team.store');
            Route::put('team/{membership}', [TeamController::class, 'update'])->name('team.update');
            Route::delete('team/{membership}', [TeamController::class, 'destroy'])->name('team.destroy');
            Route::post('team/{membership}/resend-invitation', [TeamController::class, 'resendInvitation'])
                ->middleware('throttle:6,1')
                ->name('team.resend-invitation');

            Route::get('taxes', [TaxRateController::class, 'index'])->name('taxes.index');
            Route::post('taxes', [TaxRateController::class, 'store'])->name('taxes.store');
            Route::put('taxes/{taxRate}', [TaxRateController::class, 'update'])->name('taxes.update');
            Route::delete('taxes/{taxRate}', [TaxRateController::class, 'destroy'])->name('taxes.destroy');
        });
    });

    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.stop');

    // Super-admin panel (platform owner only).
    Route::prefix('admin')->name('admin.')->middleware(['super-admin', 'two-factor'])->group(function () {
        Route::redirect('/', '/admin/companies');
        Route::resource('companies', AdminCompanyController::class)->except(['edit', 'destroy']);
        Route::post('companies/{company}/impersonate/{user}', [ImpersonationController::class, 'store'])
            ->name('companies.impersonate');
    });
});

require __DIR__.'/settings.php';
