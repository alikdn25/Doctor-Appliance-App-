<?php

use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Billing\DocumentDeliveryController;
use App\Http\Controllers\Billing\EstimateController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PaymentController;
use App\Http\Controllers\Billing\PaymentLinkController;
use App\Http\Controllers\Company\BrandController;
use App\Http\Controllers\Company\ChecklistController;
use App\Http\Controllers\Company\CompanySettingsController;
use App\Http\Controllers\Company\DetectTimezoneController;
use App\Http\Controllers\Company\PaymentProviderController;
use App\Http\Controllers\Company\ServiceController;
use App\Http\Controllers\Company\SwitchCompanyController;
use App\Http\Controllers\Company\TaxRateController;
use App\Http\Controllers\Company\TeamController;
use App\Http\Controllers\Customers\ApplianceController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\PropertyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Jobs\CalendarController;
use App\Http\Controllers\Jobs\JobController;
use App\Http\Controllers\Jobs\JobFieldController;
use App\Http\Controllers\Jobs\JobStatusController;
use App\Http\Controllers\Jobs\JobWorkController;
use App\Http\Controllers\Jobs\VisitActionController;
use App\Http\Controllers\Jobs\VisitController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PublicDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');

// Customer's online page of an estimate or invoice (link in the email; the token is the key).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('d/{token}', [PublicDocumentController::class, 'show'])->name('documents.public');
    Route::get('d/{token}/pdf', [PublicDocumentController::class, 'pdf'])->name('documents.public.pdf');
    Route::post('d/{token}/pay', [PublicDocumentController::class, 'pay'])->middleware('throttle:10,1')->name('documents.public.pay');
});

// Online payment provider webhooks (signature checked by the provider; no session, no CSRF).
Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'active'])->group(function () {

    // Tenant area: everything here runs inside the current company.
    Route::middleware(['tenant', 'two-factor'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::post('companies/{company}/switch', SwitchCompanyController::class)->name('companies.switch');

        Route::get('customers/duplicates', [CustomerController::class, 'duplicates'])->name('customers.duplicates');
        Route::resource('customers', CustomerController::class);
        Route::post('customers/{customer}/properties', [PropertyController::class, 'store'])->name('properties.store');
        Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
        Route::delete('properties/{property}', [PropertyController::class, 'destroy'])->name('properties.destroy');
        Route::post('properties/{property}/appliances', [ApplianceController::class, 'store'])->name('appliances.store');
        Route::get('appliances/{appliance}', [ApplianceController::class, 'show'])->name('appliances.show');
        Route::get('appliances/{appliance}/rating-plate', [ApplianceController::class, 'ratingPlate'])->withTrashed()->name('appliances.rating-plate');
        Route::put('appliances/{appliance}', [ApplianceController::class, 'update'])->name('appliances.update');
        Route::delete('appliances/{appliance}', [ApplianceController::class, 'destroy'])->name('appliances.destroy');

        Route::get('my-jobs', [JobController::class, 'mine'])->name('jobs.mine');
        Route::get('jobs/customers', [JobController::class, 'lookup'])->name('jobs.lookup');
        Route::resource('jobs', JobController::class);
        Route::put('jobs/{job}/status', JobStatusController::class)->name('jobs.status');
        Route::put('jobs/{job}/tech-notes', [JobWorkController::class, 'notes'])->name('jobs.tech-notes');
        Route::post('jobs/{job}/appliances', [JobWorkController::class, 'storeAppliance'])->name('jobs.appliances.store');
        Route::put('jobs/{job}/appliances/{appliance}', [JobWorkController::class, 'updateAppliance'])->name('jobs.appliances.update');
        Route::post('jobs/{job}/photos', [JobFieldController::class, 'storePhoto'])->name('jobs.photos.store');
        Route::get('jobs/{job}/photos/{photo}', [JobFieldController::class, 'showPhoto'])->name('jobs.photos.show');
        Route::delete('jobs/{job}/photos/{photo}', [JobFieldController::class, 'destroyPhoto'])->name('jobs.photos.destroy');
        Route::post('jobs/{job}/appliances/{appliance}/rating-plate', [JobFieldController::class, 'ratingPlate'])->name('jobs.appliances.rating-plate');
        Route::put('jobs/{job}/checklist/{item}', [JobFieldController::class, 'toggleChecklistItem'])->name('jobs.checklist.toggle');
        Route::post('jobs/{job}/signature', [JobFieldController::class, 'storeSignature'])->name('jobs.signature.store');
        Route::get('jobs/{job}/signature', [JobFieldController::class, 'showSignature'])->name('jobs.signature.show');
        Route::post('jobs/{job}/visits', [VisitController::class, 'store'])->name('visits.store');
        Route::put('visits/{visit}', [VisitController::class, 'update'])->name('visits.update');
        Route::delete('visits/{visit}', [VisitController::class, 'destroy'])->name('visits.destroy');
        Route::put('visits/{visit}/move', [VisitController::class, 'move'])->name('visits.move');
        Route::get('calendar', CalendarController::class)->name('calendar');
        Route::post('visits/{visit}/on-my-way', [VisitActionController::class, 'onMyWay'])->name('visits.on-my-way');
        Route::post('visits/{visit}/start', [VisitActionController::class, 'start'])->name('visits.start');
        Route::post('visits/{visit}/finish', [VisitActionController::class, 'finish'])->name('visits.finish');

        // Estimates, invoices and payments (created from a job).
        Route::get('jobs/{job}/estimates/create', [EstimateController::class, 'create'])->name('estimates.create');
        Route::post('jobs/{job}/estimates', [EstimateController::class, 'store'])->name('estimates.store');
        Route::get('estimates/{estimate}', [EstimateController::class, 'show'])->name('estimates.show');
        Route::get('estimates/{estimate}/edit', [EstimateController::class, 'edit'])->name('estimates.edit');
        Route::put('estimates/{estimate}', [EstimateController::class, 'update'])->name('estimates.update');
        Route::delete('estimates/{estimate}', [EstimateController::class, 'destroy'])->name('estimates.destroy');
        Route::put('estimates/{estimate}/decision', [EstimateController::class, 'decide'])->name('estimates.decide');
        Route::post('estimates/{estimate}/invoice', [EstimateController::class, 'convert'])->name('estimates.convert');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('jobs/{job}/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('jobs/{job}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
        Route::post('invoices/{invoice}/payment-link', [PaymentLinkController::class, 'store'])->name('invoices.payment-link');
        Route::get('estimates/{estimate}/pdf', [DocumentDeliveryController::class, 'estimatePdf'])->name('estimates.pdf');
        Route::get('invoices/{invoice}/pdf', [DocumentDeliveryController::class, 'invoicePdf'])->name('invoices.pdf');
        Route::post('estimates/{estimate}/send', [DocumentDeliveryController::class, 'sendEstimate'])->middleware('throttle:30,1')->name('estimates.send');
        Route::post('invoices/{invoice}/send', [DocumentDeliveryController::class, 'sendInvoice'])->middleware('throttle:30,1')->name('invoices.send');

        // Connecting the company's own payment provider account (OAuth). The callback URL is registered at the provider.
        Route::get('payment-providers/{provider}/connect', [PaymentProviderController::class, 'connect'])->name('payment-providers.connect');
        Route::get('payment-providers/{provider}/callback', [PaymentProviderController::class, 'callback'])->name('payment-providers.callback');
        Route::delete('payment-providers/{provider}', [PaymentProviderController::class, 'disconnect'])->name('payment-providers.disconnect');

        Route::prefix('company')->group(function () {
            Route::get('settings', [CompanySettingsController::class, 'edit'])->name('company.settings.edit');
            Route::put('settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');
            Route::put('timezone', DetectTimezoneController::class)->name('company.timezone.detect');
            Route::get('checklists', [ChecklistController::class, 'edit'])->name('company.checklists.edit');
            Route::put('checklists', [ChecklistController::class, 'update'])->name('company.checklists.update');
            Route::get('services', [ServiceController::class, 'edit'])->name('company.services.edit');
            Route::put('services', [ServiceController::class, 'update'])->name('company.services.update');

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
