<?php

use App\Http\Controllers\Accounting\BusinessExpenseCategoryController;
use App\Http\Controllers\Accounting\BusinessExpenseController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\SmsRegistrationController as AdminSmsRegistrationController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\Billing\CashController;
use App\Http\Controllers\Billing\DocumentDeliveryController;
use App\Http\Controllers\Billing\EstimateController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PaymentController;
use App\Http\Controllers\Billing\PaymentLinkController;
use App\Http\Controllers\Billing\PriceBookController;
use App\Http\Controllers\Company\BrandController;
use App\Http\Controllers\Company\ChecklistController;
use App\Http\Controllers\Company\CompanySettingsController;
use App\Http\Controllers\Company\DetectTimezoneController;
use App\Http\Controllers\Company\GoogleProfileController;
use App\Http\Controllers\Company\MemberTransitionController;
use App\Http\Controllers\Company\MessagingSettingsController;
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
use App\Http\Controllers\Jobs\JobBacklogController;
use App\Http\Controllers\Jobs\JobCloseController;
use App\Http\Controllers\Jobs\JobController;
use App\Http\Controllers\Jobs\JobCostController;
use App\Http\Controllers\Jobs\JobFieldController;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Http\Controllers\Jobs\JobStatusController;
use App\Http\Controllers\Jobs\JobWarrantyController;
use App\Http\Controllers\Jobs\JobWorkController;
use App\Http\Controllers\Jobs\VisitActionController;
use App\Http\Controllers\Jobs\VisitController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\Onboarding\CompanyController as OnboardingCompanyController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\SmsInboxController;
use App\Http\Controllers\SmsWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');

// Customer's online page of an estimate or invoice (link in the email; the token is the key).
Route::middleware('throttle:60,1')->group(function () {
    Route::get('d/{token}', [PublicDocumentController::class, 'show'])->name('documents.public');
    Route::get('d/{token}/pdf', [PublicDocumentController::class, 'pdf'])->name('documents.public.pdf');
    Route::post('d/{token}/pay', [PublicDocumentController::class, 'pay'])->middleware('throttle:10,1')->name('documents.public.pay');
    Route::post('d/{token}/approve', [PublicDocumentController::class, 'approve'])->middleware('throttle:10,1')->name('documents.public.approve');
    Route::post('d/{token}/decline', [PublicDocumentController::class, 'decline'])->middleware('throttle:10,1')->name('documents.public.decline');
    Route::post('d/{token}/deposit', [PublicDocumentController::class, 'deposit'])->middleware('throttle:10,1')->name('documents.public.deposit');
});

// SMS provider webhooks: incoming texts and delivery status (signature checked; no session, no CSRF).
Route::middleware('throttle:300,1')->group(function () {
    Route::post('webhooks/sms/{provider}', [SmsWebhookController::class, 'inbound'])->name('webhooks.sms.inbound');
    Route::post('webhooks/sms/{provider}/status', [SmsWebhookController::class, 'status'])->name('webhooks.sms.status');
});

// Online payment provider webhooks (signature checked by the provider; no session, no CSRF).
Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

// Home is the Jobs list (technicians, who may not see it, are sent on to My Jobs).
Route::get('/', fn () => redirect()->route(auth()->check() ? 'jobs.index' : 'login'))->name('home');

Route::middleware(['auth', 'active'])->group(function () {
    Route::middleware(['verified', 'super-admin'])->group(function () {
        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::post('admin/companies/{company}/workspace', [WorkspaceController::class, 'store'])->name('admin.companies.workspace');
    });

    Route::middleware('verified')->group(function () {
        Route::get('onboarding/company', [OnboardingCompanyController::class, 'create'])->name('onboarding.company.create');
        Route::post('onboarding/company', [OnboardingCompanyController::class, 'store'])->middleware('throttle:5,1')->name('onboarding.company.store');
    });

    // Tenant area: everything here runs inside the current company.
    Route::middleware(['verified', 'tenant'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('messages', [SmsInboxController::class, 'index'])->name('messages.index');
        Route::post('messages/read', [SmsInboxController::class, 'read'])->name('messages.read');
        Route::post('messages/send', [SmsInboxController::class, 'send'])->middleware('throttle:30,1')->name('messages.send');

        Route::post('companies/{company}/switch', SwitchCompanyController::class)->name('companies.switch');

        Route::get('customers/avatar', [CustomerController::class, 'avatar'])->middleware('throttle:120,1')->name('customers.avatar');
        Route::get('customers/duplicates', [CustomerController::class, 'duplicates'])->name('customers.duplicates');
        Route::patch('customers/{customer}/icon', [CustomerController::class, 'updateAvatar'])->name('customers.icon');
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
        Route::get('jobs/not-completed', JobBacklogController::class)->name('jobs.backlog');
        Route::get('jobs/customers', [JobController::class, 'lookup'])->name('jobs.lookup');
        Route::get('jobs/deleted', [JobController::class, 'trash'])->name('jobs.trash');
        Route::post('jobs/{job}/restore', [JobController::class, 'restore'])->withTrashed()->name('jobs.restore');
        Route::resource('jobs', JobController::class);
        Route::put('jobs/{job}/status', JobStatusController::class)->name('jobs.status');
        Route::post('jobs/{job}/close', JobCloseController::class)->name('jobs.close');
        Route::post('jobs/{job}/costs', [JobCostController::class, 'storeCost'])->name('jobs.costs.store');
        Route::put('jobs/{job}/costs/{cost}', [JobCostController::class, 'updateCost'])->name('jobs.costs.update');
        Route::delete('jobs/{job}/costs/{cost}', [JobCostController::class, 'destroyCost'])->name('jobs.costs.destroy');
        Route::post('jobs/{job}/receipts', [JobCostController::class, 'storeReceipt'])->name('jobs.receipts.store');
        Route::post('receipts/{receipt}/links', [JobCostController::class, 'linkReceipt'])->name('receipts.link');
        Route::get('receipts/{receipt}', [JobCostController::class, 'showReceipt'])->name('receipts.show');
        Route::delete('receipts/{receipt}', [JobCostController::class, 'destroyReceipt'])->name('receipts.destroy');
        Route::put('jobs/{job}/warranties', [JobWarrantyController::class, 'update'])->name('jobs.warranties.update');
        Route::get('jobs/{job}/warranties', [JobWarrantyController::class, 'onDate'])->name('jobs.warranties');
        Route::put('jobs/{job}/tech-notes', [JobWorkController::class, 'notes'])->name('jobs.tech-notes');
        Route::post('jobs/{job}/appliances', [JobWorkController::class, 'storeAppliance'])->name('jobs.appliances.store');
        Route::put('jobs/{job}/appliances/{appliance}', [JobWorkController::class, 'updateAppliance'])->name('jobs.appliances.update');
        Route::post('jobs/{job}/photos', [JobFieldController::class, 'storePhoto'])->name('jobs.photos.store');
        Route::get('jobs/{job}/photos/{photo}', [JobFieldController::class, 'showPhoto'])->name('jobs.photos.show');
        Route::delete('jobs/{job}/photos/{photo}', [JobFieldController::class, 'destroyPhoto'])->name('jobs.photos.destroy');
        Route::post('jobs/{job}/appliances/{appliance}/rating-plate', [JobFieldController::class, 'ratingPlate'])->name('jobs.appliances.rating-plate');
        Route::put('jobs/{job}/checklist/{item}', [JobFieldController::class, 'toggleChecklistItem'])->name('jobs.checklist.toggle');
        Route::put('jobs/{job}/bring/{item}', [JobFieldController::class, 'toggleBringItem'])->name('jobs.bring.toggle');
        Route::post('jobs/{job}/signature', [JobFieldController::class, 'storeSignature'])->name('jobs.signature.store');
        Route::get('jobs/{job}/signature', [JobFieldController::class, 'showSignature'])->name('jobs.signature.show');
        Route::post('jobs/{job}/visits', [VisitController::class, 'store'])->name('visits.store');
        Route::put('visits/{visit}', [VisitController::class, 'update'])->name('visits.update');
        Route::delete('visits/{visit}', [VisitController::class, 'destroy'])->name('visits.destroy');
        Route::put('visits/{visit}/move', [VisitController::class, 'move'])->name('visits.move');
        Route::get('calendar', CalendarController::class)->name('calendar');
        Route::post('visits/{visit}/on-my-way', [VisitActionController::class, 'onMyWay'])->name('visits.on-my-way');
        Route::post('visits/{visit}/start', [VisitActionController::class, 'start'])->name('visits.start');
        Route::get('visits/{visit}/finish', [VisitActionController::class, 'showFinish'])->name('visits.finish-screen');
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
        Route::post('estimates/{estimate}/revise', [EstimateController::class, 'revise'])->name('estimates.revise');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/create', [InvoiceController::class, 'start'])->name('invoices.start');
        Route::get('jobs/{job}/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('jobs/{job}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
        Route::post('invoices/{invoice}/refund', [PaymentController::class, 'refund'])->name('invoices.refund');
        Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('business-expenses/export.csv', [BusinessExpenseController::class, 'export'])->name('expenses.download');
        Route::get('business-expenses/{expense}/receipt', [BusinessExpenseController::class, 'receipt'])->name('expenses.receipt');
        Route::resource('business-expenses', BusinessExpenseController::class)->parameters(['business-expenses' => 'expense'])->names('expenses')->except('show');
        Route::post('business-expense-categories', [BusinessExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('business-expense-categories/{category}', [BusinessExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::get('reports/expenses.csv', [ReportController::class, 'expenses'])->name('reports.expenses');
        Route::get('reports/receipts.zip', [ReportController::class, 'receipts'])->name('reports.receipts');
        Route::get('cash', [CashController::class, 'index'])->name('cash.index');
        Route::post('cash/deposits', [CashController::class, 'deposit'])->name('cash.deposit');
        Route::post('cash/{movement}/reverse', [CashController::class, 'reverse'])->name('cash.reverse');
        Route::get('pricebook/history', [PriceBookController::class, 'history'])->name('pricebook.history');
        Route::post('pricebook', [PriceBookController::class, 'store'])->name('pricebook.store');
        Route::post('invoices/{invoice}/payment-link', [PaymentLinkController::class, 'store'])->name('invoices.payment-link');
        Route::get('estimates/{estimate}/pdf', [DocumentDeliveryController::class, 'estimatePdf'])->name('estimates.pdf');
        Route::get('invoices/{invoice}/pdf', [DocumentDeliveryController::class, 'invoicePdf'])->name('invoices.pdf');
        Route::post('estimates/{estimate}/send', [DocumentDeliveryController::class, 'sendEstimate'])->middleware('throttle:30,1')->name('estimates.send');
        Route::post('invoices/{invoice}/send', [DocumentDeliveryController::class, 'sendInvoice'])->middleware('throttle:30,1')->name('invoices.send');
        Route::post('estimates/{estimate}/sms', [DocumentDeliveryController::class, 'smsEstimate'])->middleware('throttle:30,1')->name('estimates.sms');
        Route::post('invoices/{invoice}/sms', [DocumentDeliveryController::class, 'smsInvoice'])->middleware('throttle:30,1')->name('invoices.sms');
        Route::post('jobs/{job}/sms', [JobMessageController::class, 'sms'])->middleware('throttle:30,1')->name('jobs.sms');
        Route::post('jobs/{job}/messages/opened', [JobMessageController::class, 'opened'])->name('jobs.messages.opened');
        Route::put('jobs/{job}/ask-for-review', [JobMessageController::class, 'askForReview'])->name('jobs.ask-for-review');

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
            Route::get('messaging', [MessagingSettingsController::class, 'edit'])->name('company.messaging.edit');
            Route::put('messaging', [MessagingSettingsController::class, 'update'])->name('company.messaging.update');
            Route::post('messaging/number', [MessagingSettingsController::class, 'provision'])->middleware('throttle:5,1')->name('company.messaging.provision');
            Route::put('messaging/registration', [MessagingSettingsController::class, 'registration'])->name('company.messaging.registration');
            Route::get('google-reviews', [GoogleProfileController::class, 'edit'])->name('company.google-profiles.edit');
            Route::put('google-reviews', [GoogleProfileController::class, 'update'])->name('company.google-profiles.update');
            Route::get('services', [ServiceController::class, 'edit'])->name('company.services.edit');
            Route::put('services', [ServiceController::class, 'update'])->name('company.services.update');

            Route::resource('brands', BrandController::class)->except('show');

            Route::get('team', [TeamController::class, 'index'])->name('team.index');
            Route::post('team', [TeamController::class, 'store'])->name('team.store');
            Route::put('team/{membership}', [TeamController::class, 'update'])->name('team.update');
            Route::post('team/{membership}/transfer-jobs', [MemberTransitionController::class, 'transfer'])->name('team.transfer-jobs');
            Route::post('team/{membership}/replace', [MemberTransitionController::class, 'replace'])->middleware('throttle:6,1')->name('team.replace');
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
    Route::prefix('admin')->name('admin.')->middleware(['verified', 'super-admin'])->group(function () {
        Route::redirect('/', '/admin/companies');
        Route::resource('companies', AdminCompanyController::class)->except(['edit', 'destroy']);
        Route::put('companies/{company}/sms-registration', [AdminSmsRegistrationController::class, 'update'])->name('companies.sms-registration');
        Route::post('companies/{company}/impersonate/{user}', [ImpersonationController::class, 'store'])
            ->name('companies.impersonate');
    });
});

require __DIR__.'/settings.php';
