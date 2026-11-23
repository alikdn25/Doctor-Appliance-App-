<?php

use App\Http\Controllers\Accounting\BusinessExpenseCategoryController;
use App\Http\Controllers\Accounting\BusinessExpenseController;
use App\Http\Controllers\Accounting\ExpensesHubController;
use App\Http\Controllers\Accounting\TaxReserveController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\SmsRegistrationController as AdminSmsRegistrationController;
use App\Http\Controllers\Admin\WorkspaceController;
use App\Http\Controllers\AndroidAppController;
use App\Http\Controllers\AppReviewController;
use App\Http\Controllers\AppVersionController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Billing\CashController;
use App\Http\Controllers\Billing\ChargeController;
use App\Http\Controllers\Billing\DebtorsController;
use App\Http\Controllers\Billing\DocumentDeliveryController;
use App\Http\Controllers\Billing\EstimateController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\InvoiceReviewRequestController;
use App\Http\Controllers\Billing\PaidController;
use App\Http\Controllers\Billing\PaymentController;
use App\Http\Controllers\Billing\PaymentLinkController;
use App\Http\Controllers\Billing\PriceBookController;
use App\Http\Controllers\Company\AnswersController;
use App\Http\Controllers\Company\AutoMessagesController;
use App\Http\Controllers\Company\BotTokenController;
use App\Http\Controllers\Company\BrandController;
use App\Http\Controllers\Company\CompanySettingsController;
use App\Http\Controllers\Company\DetectTimezoneController;
use App\Http\Controllers\Company\GoogleProfileController;
use App\Http\Controllers\Company\MemberTransitionController;
use App\Http\Controllers\Company\MessagingSettingsController;
use App\Http\Controllers\Company\MorningController;
use App\Http\Controllers\Company\ServiceAreaController;
use App\Http\Controllers\Company\PaymentProviderController;
use App\Http\Controllers\Company\ServiceController;
use App\Http\Controllers\Company\SquareImportController;
use App\Http\Controllers\Company\SwitchCompanyController;
use App\Http\Controllers\Company\TaxRateController;
use App\Http\Controllers\Company\TeamController;
use App\Http\Controllers\Company\WorkModeController;
use App\Http\Controllers\Customers\ApplianceController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\PropertyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\Jobs\AssignmentController;
use App\Http\Controllers\Jobs\BookingConfirmationController;
use App\Http\Controllers\Jobs\BookingTextController;
use App\Http\Controllers\Jobs\CalendarController;
use App\Http\Controllers\Jobs\JobAddressController;
use App\Http\Controllers\Jobs\JobBacklogController;
use App\Http\Controllers\Jobs\JobCloseController;
use App\Http\Controllers\Jobs\JobController;
use App\Http\Controllers\Jobs\JobCostController;
use App\Http\Controllers\Jobs\JobDetailsController;
use App\Http\Controllers\Jobs\JobFieldController;
use App\Http\Controllers\Jobs\JobHandoffController;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Http\Controllers\Jobs\JobMessageFillController;
use App\Http\Controllers\Jobs\JobNoteController;
use App\Http\Controllers\Jobs\JobPartsController;
use App\Http\Controllers\Jobs\JobStatusController;
use App\Http\Controllers\Jobs\JobVoiceController;
use App\Http\Controllers\Jobs\JobWarrantyController;
use App\Http\Controllers\Jobs\JobWorkController;
use App\Http\Controllers\Jobs\MapController;
use App\Http\Controllers\Jobs\NearbyController;
use App\Http\Controllers\Jobs\QuickBookingController;
use App\Http\Controllers\Jobs\VisitActionController;
use App\Http\Controllers\Jobs\VisitController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\Mileage\TripController;
use App\Http\Controllers\Onboarding\CompanyController as OnboardingCompanyController;
use App\Http\Controllers\Parts\PartsController;
use App\Http\Controllers\Parts\SupplierController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\PushActionController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\SelfBookingController;
use App\Http\Controllers\ServiceWorkerController;
use App\Http\Controllers\Settings\MenuModeController;
use App\Http\Controllers\SmsAssistController;
use App\Http\Controllers\SmsInboxController;
use App\Http\Controllers\SmsWebhookController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\VoiceWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('manifest.webmanifest', ManifestController::class)->name('manifest');
// The service worker carries the running version, so each deploy installs a fresh one.
Route::get('sw.js', ServiceWorkerController::class)->name('service-worker');
// The running version: the app compares it with the one it loaded to offer "Update" after a deploy.
Route::get('app-version', AppVersionController::class)->middleware('throttle:app-version')->name('app-version');

// Customer's online page of an estimate or invoice (link in the email; the token is the key).
Route::middleware('throttle:60,1')->group(function () {
    // Self-booking by link (no sign-in): 7 days, one booking.
    Route::get('book/{token}', [SelfBookingController::class, 'show'])->name('self-booking.show');
    Route::post('book/{token}/nearby', [SelfBookingController::class, 'nearby'])->middleware('throttle:20,1')->name('self-booking.nearby');
    Route::post('book/{token}', [SelfBookingController::class, 'store'])->middleware('throttle:10,1')->name('self-booking.store');
    // "Hand to technician": the job's photos for an outside technician (signed link, no sign-in).
    Route::get('shared/jobs/{job}/photos', [JobHandoffController::class, 'photos'])->whereNumber('job')->name('shared.job-photos');
    Route::get('shared/jobs/{job}/photos/{photo}', [JobHandoffController::class, 'photo'])->whereNumber('job')->whereNumber('photo')->name('shared.job-photo');
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
    Route::post('webhooks/voice/telnyx', VoiceWebhookController::class)->name('webhooks.voice.telnyx');
});

// The Android app (Capacitor shell with the card reader), built by GitHub Actions and placed on the server.
Route::get('download/android.apk', [PaidController::class, 'androidApk'])->middleware('throttle:30,1')->name('download.android');
Route::get('download/android.json', [AndroidAppController::class, 'latest'])->middleware('throttle:app-version')->name('download.android.latest');

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
        // A button on a push notification (the token names the company and the person).
        Route::post('push-actions/{token}/{action}', PushActionController::class)->where('token', '[A-Za-z0-9]{48}')
            ->where('action', '[a-z_]{1,20}')->middleware('throttle:30,1')->name('push-actions.run');
    });

    // Tenant area: everything here runs inside the current company.
    Route::middleware(['verified', 'tenant'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('today', TodayController::class)->name('today');
        // Google sends the Owner back here after "Connect Google Drive" (Company → Morning SMS & backup).
        Route::get('settings/google-drive/callback', [MorningController::class, 'callback'])->name('google-drive.callback');

        // AI assistant (Owner only).
        Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
        Route::post('assistant/ask', [AssistantController::class, 'ask'])->middleware('throttle:assistant')->name('assistant.ask');
        Route::get('assistant/reviews', [AppReviewController::class, 'index'])->name('app-reviews.index');
        Route::post('assistant/reviews', [AppReviewController::class, 'store'])->middleware('throttle:assistant')->name('app-reviews.store');
        Route::get('assistant/reviews/{review}', [AppReviewController::class, 'show'])->name('app-reviews.show');
        Route::put('assistant/review-suggestions/{suggestion}', [AppReviewController::class, 'status'])->name('app-reviews.suggestion');

        // The action log for App review and "Inconvenient" (every role).
        Route::post('usage/events', [UsageController::class, 'store'])->middleware('throttle:usage')->name('usage.store');
        Route::post('usage/inconvenient', [UsageController::class, 'inconvenient'])->middleware('throttle:30,1')->name('usage.inconvenient');
        Route::post('assistant/find-problems', [AssistantController::class, 'findProblems'])->middleware('throttle:assistant')->name('assistant.find-problems');
        Route::post('assistant/new-chat', [AssistantController::class, 'fresh'])->name('assistant.new-chat');
        Route::get('assistant/photos/{message}/{index}', [AssistantController::class, 'photo'])->whereNumber('index')->name('assistant.photo');
        Route::post('assistant/actions/{action}/confirm', [AssistantController::class, 'confirm'])->name('assistant.actions.confirm');
        Route::post('assistant/actions/{action}/cancel', [AssistantController::class, 'cancel'])->name('assistant.actions.cancel');

        Route::get('messages', [SmsInboxController::class, 'index'])->name('messages.index');
        Route::get('messages/older', [SmsInboxController::class, 'older'])->name('messages.older');
        Route::post('messages/read', [SmsInboxController::class, 'read'])->name('messages.read');
        Route::post('messages/translate', [SmsInboxController::class, 'translate'])->middleware('throttle:30,1')->name('messages.translate');
        Route::post('messages/send', [SmsInboxController::class, 'send'])->middleware('throttle:30,1')->name('messages.send');
        Route::post('messages/done', [SmsAssistController::class, 'done'])->name('messages.done');
        Route::post('messages/{message}/not-waiting', [SmsInboxController::class, 'notWaiting'])->name('messages.not-waiting');
        Route::post('messages/{message}/ai-actions/{index}/confirm', [SmsAssistController::class, 'confirm'])->whereNumber('index')->middleware('throttle:30,1')->name('messages.ai.confirm');
        Route::post('messages/{message}/ai-actions/{index}/dismiss', [SmsAssistController::class, 'dismiss'])->whereNumber('index')->name('messages.ai.dismiss');
        Route::post('messages/{message}/plate', [SmsAssistController::class, 'plate'])->name('messages.ai.plate');
        Route::get('messages/{message}/media/{index}', [SmsAssistController::class, 'media'])->whereNumber('index')->name('messages.media');

        Route::post('companies/{company}/switch', SwitchCompanyController::class)->name('companies.switch');

        Route::get('customers/avatar', [CustomerController::class, 'avatar'])->middleware('throttle:120,1')->name('customers.avatar');
        Route::get('customers/duplicates', [CustomerController::class, 'duplicates'])->name('customers.duplicates');
        Route::patch('customers/{customer}/notes', [CustomerController::class, 'updateNotes'])->name('customers.notes');
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
        Route::put('my-jobs/panes', [JobController::class, 'panes'])->name('jobs.panes');
        Route::get('jobs/not-completed', JobBacklogController::class)->name('jobs.backlog');
        Route::get('jobs/customers', [JobController::class, 'lookup'])->name('jobs.lookup');
        Route::post('jobs/parse-message', BookingTextController::class)->middleware('throttle:30,1')->name('jobs.parse-message');
        Route::get('jobs/quick-booking', [QuickBookingController::class, 'show'])->name('jobs.quick-booking');
        Route::post('jobs/quick-booking/parse', [QuickBookingController::class, 'parse'])->middleware('throttle:quick-booking')->name('jobs.quick-booking.parse');
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
        Route::put('jobs/{job}/instructions', [JobWorkController::class, 'instructions'])->name('jobs.instructions');
        Route::post('jobs/{job}/appliances', [JobWorkController::class, 'storeAppliance'])->name('jobs.appliances.store');
        Route::put('jobs/{job}/appliances/{appliance}', [JobWorkController::class, 'updateAppliance'])->name('jobs.appliances.update');
        Route::post('jobs/{job}/photos', [JobFieldController::class, 'storePhoto'])->name('jobs.photos.store');
        // Windows by area: days with a job near this address.
        Route::get('booking/nearby', [NearbyController::class, 'show'])->name('booking.nearby');
        Route::post('jobs/{job}/handoff/preview', [JobHandoffController::class, 'preview'])->name('jobs.handoff.preview');
        Route::post('jobs/{job}/handoff', [JobHandoffController::class, 'store'])->middleware('throttle:10,1')->name('jobs.handoff.store');
        Route::get('jobs/{job}/photos/{photo}', [JobFieldController::class, 'showPhoto'])->name('jobs.photos.show');
        Route::delete('jobs/{job}/photos/{photo}', [JobFieldController::class, 'destroyPhoto'])->name('jobs.photos.destroy');
        Route::post('jobs/{job}/appliances/{appliance}/rating-plate', [JobFieldController::class, 'ratingPlate'])->name('jobs.appliances.rating-plate');
        Route::put('jobs/{job}/bring/{item}', [JobFieldController::class, 'toggleBringItem'])->name('jobs.bring.toggle');
        Route::post('jobs/{job}/signature', [JobFieldController::class, 'storeSignature'])->name('jobs.signature.store');
        Route::get('jobs/{job}/signature', [JobFieldController::class, 'showSignature'])->name('jobs.signature.show');
        Route::post('jobs/{job}/visits', [VisitController::class, 'store'])->name('visits.store');
        Route::put('visits/{visit}', [VisitController::class, 'update'])->name('visits.update');
        Route::delete('visits/{visit}', [VisitController::class, 'destroy'])->name('visits.destroy');
        Route::put('visits/{visit}/move', [VisitController::class, 'move'])->name('visits.move');
        Route::get('calendar', CalendarController::class)->name('calendar');
        Route::get('map', [MapController::class, 'show'])->name('map');
        Route::post('map/order', [MapController::class, 'order'])->name('map.order');
        Route::post('map/plan', [MapController::class, 'plan'])->middleware('throttle:map-plan')->name('map.plan');
        Route::get('technicians/load', [AssignmentController::class, 'load'])->name('technicians.load');
        Route::get('team/{membership}/open-visits', [AssignmentController::class, 'openVisits'])->name('technicians.open-visits');
        Route::put('jobs/{job}/address', JobAddressController::class)->name('jobs.address');
        Route::post('jobs/{job}/fill-from-message', JobMessageFillController::class)->middleware('throttle:30,1')->name('jobs.fill-from-message');
        Route::put('jobs/{job}/details', JobDetailsController::class)->name('jobs.details');
        // Voice (owner's task of November 18): quick edit of a booking, close by voice. Nothing saved without Save / Confirm.
        Route::post('jobs/{job}/quick-edit', [JobVoiceController::class, 'quickEdit'])->middleware('throttle:assistant')->name('jobs.quick-edit');
        Route::post('jobs/{job}/voice-close/read', [JobVoiceController::class, 'readClose'])->middleware('throttle:assistant')->name('jobs.voice-close.read');
        Route::post('jobs/{job}/voice-close/preview', [JobVoiceController::class, 'previewClose'])->middleware('throttle:120,1')->name('jobs.voice-close.preview');
        Route::post('jobs/{job}/voice-close', [JobVoiceController::class, 'confirmClose'])->name('jobs.voice-close');
        // The part a job waits for, and "Part arrived" (text with free windows, sent on Confirm).
        Route::put('jobs/{job}/part', [JobPartsController::class, 'update'])->name('jobs.part');
        // Parts page (owner's task of November 19): Order / Waiting, several parts per job, the job's status follows.
        Route::get('parts', [PartsController::class, 'index'])->name('parts.index');
        Route::post('jobs/{job}/parts', [PartsController::class, 'store'])->name('jobs.parts.store');
        Route::post('jobs/{job}/parts/ordered', [PartsController::class, 'orderedAll'])->name('jobs.parts.ordered');
        Route::put('parts/{part}', [PartsController::class, 'update'])->name('parts.update');
        Route::post('parts/{part}/bought', [PartsController::class, 'bought'])->name('parts.bought');
        Route::post('parts/{part}/ordered', [PartsController::class, 'ordered'])->name('parts.ordered');
        Route::post('parts/{part}/arrived', [PartsController::class, 'arrived'])->name('parts.arrived');
        Route::delete('parts/{part}', [PartsController::class, 'destroy'])->name('parts.destroy');
        Route::get('jobs/{job}/part-arrived', [JobPartsController::class, 'arrived'])->name('jobs.part-arrived');
        Route::post('jobs/{job}/part-arrived', [JobPartsController::class, 'sendArrived'])->middleware('throttle:30,1')->name('jobs.part-arrived.send');
        Route::put('jobs/{job}/technician', [AssignmentController::class, 'assign'])->name('jobs.technician');
        Route::post('visits/transfer', [AssignmentController::class, 'transfer'])->name('visits.transfer');
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
        // Debtors: import, Unpaid, soft payment reminders (owner's task of November 19).
        Route::get('invoices/unpaid', [DebtorsController::class, 'unpaid'])->name('invoices.unpaid');
        Route::get('invoices/import-debtors', [DebtorsController::class, 'import'])->name('invoices.debtors.import');
        Route::post('invoices/import-debtors/preview', [DebtorsController::class, 'preview'])->middleware('throttle:20,1')->name('invoices.debtors.preview');
        Route::post('invoices/import-debtors', [DebtorsController::class, 'store'])->name('invoices.debtors.store');
        Route::post('invoices/reminders/preview', [DebtorsController::class, 'reminderPreview'])->name('invoices.reminders.preview');
        Route::post('invoices/reminders', [DebtorsController::class, 'remind'])->middleware('throttle:10,1')->name('invoices.reminders.send');
        Route::get('jobs/{job}/invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('jobs/{job}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::get('invoices/{invoice}/attachments/{attachment}', [InvoiceController::class, 'attachment'])->name('invoices.attachment');
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
        Route::post('invoices/{invoice}/refund', [PaymentController::class, 'refund'])->name('invoices.refund');
        Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');

        // Take payment, New charge and the Paid screen (card reader, cash, e-transfer; owner's task of November 21).
        Route::get('charges/new', [ChargeController::class, 'create'])->name('charges.create');
        Route::post('charges/preview', [ChargeController::class, 'preview'])->middleware('throttle:120,1')->name('charges.preview');
        Route::get('charges/lookup', [ChargeController::class, 'lookup'])->middleware('throttle:120,1')->name('charges.lookup');
        Route::post('charges', [ChargeController::class, 'store'])->middleware('throttle:60,1')->name('charges.store');
        Route::post('charges/{charge}/complete', [ChargeController::class, 'complete'])->middleware('throttle:60,1')->name('charges.complete');
        Route::post('charges/{charge}/fail', [ChargeController::class, 'fail'])->middleware('throttle:60,1')->name('charges.fail');
        Route::get('invoices/{invoice}/take-payment', [ChargeController::class, 'invoice'])->name('invoices.take-payment');
        Route::get('jobs/{job}/take-payment', [ChargeController::class, 'job'])->name('jobs.take-payment');
        Route::post('jobs/{job}/quick-invoice', [ChargeController::class, 'quickInvoice'])->name('jobs.quick-invoice');
        // The Android app's download page (Owner only; owner's task of November 22).
        Route::get('android', [AndroidAppController::class, 'show'])->name('android.show');
        Route::get('payments/reader/session', [PaidController::class, 'readerSession'])->middleware('throttle:30,1')->name('payments.reader.session');
        Route::get('payments/{payment}/paid', [PaidController::class, 'show'])->name('payments.paid');
        Route::post('payments/{payment}/send-receipt', [PaidController::class, 'send'])->middleware('throttle:30,1')->name('payments.send-receipt');
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('business-expenses/export.csv', [BusinessExpenseController::class, 'export'])->name('expenses.download');
        Route::post('business-expenses/read-receipt', [BusinessExpenseController::class, 'readReceipt'])->middleware('throttle:assistant')->name('expenses.read-receipt');
        Route::get('business-expenses/{expense}/receipt', [BusinessExpenseController::class, 'receipt'])->name('expenses.receipt');
        Route::resource('business-expenses', BusinessExpenseController::class)->parameters(['business-expenses' => 'expense'])->names('expenses')->except('show');
        Route::post('business-expense-categories', [BusinessExpenseCategoryController::class, 'store'])->name('expense-categories.store');
        Route::put('business-expense-categories/{category}', [BusinessExpenseCategoryController::class, 'update'])->name('expense-categories.update');
        Route::get('reports/expenses.csv', [ReportController::class, 'expenses'])->name('reports.expenses');
        Route::get('reports/receipts.zip', [ReportController::class, 'receipts'])->name('reports.receipts');
        Route::get('trips', [TripController::class, 'index'])->name('trips.index');
        Route::get('trips/export.csv', [TripController::class, 'export'])->name('trips.export');
        Route::post('trips', [TripController::class, 'store'])->name('trips.store');
        Route::post('trips/quick', [TripController::class, 'quick'])->name('trips.quick');
        Route::put('trips/start-address', [TripController::class, 'startAddress'])->name('trips.start-address');
        Route::put('trips/{trip}', [TripController::class, 'update'])->name('trips.update');
        Route::post('trips/{trip}/confirm', [TripController::class, 'confirm'])->name('trips.confirm');
        Route::delete('trips/{trip}', [TripController::class, 'destroy'])->name('trips.destroy');
        Route::get('tasks/people', [TaskController::class, 'people'])->name('tasks.people');
        Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::post('tasks/parse', [TaskController::class, 'parse'])->middleware('throttle:assistant')->name('tasks.parse');
        Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::post('tasks/{task}/done', [TaskController::class, 'done'])->name('tasks.done');
        Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        Route::post('jobs/{job}/notes', [JobNoteController::class, 'store'])->name('jobs.notes.store');
        Route::delete('job-notes/{note}', [JobNoteController::class, 'destroy'])->name('jobs.notes.destroy');
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
        Route::post('estimates/{estimate}/link', [DocumentDeliveryController::class, 'estimateLink'])->middleware('throttle:30,1')->name('estimates.link');
        Route::post('invoices/{invoice}/link', [DocumentDeliveryController::class, 'invoiceLink'])->middleware('throttle:30,1')->name('invoices.link');
        Route::post('invoices/{invoice}/receipt', [DocumentDeliveryController::class, 'sendReceipt'])->middleware('throttle:30,1')->name('invoices.receipt');
        Route::post('invoices/{invoice}/review-request', InvoiceReviewRequestController::class)->middleware('throttle:30,1')->name('invoices.review-request');
        Route::post('jobs/{job}/sms', [JobMessageController::class, 'sms'])->middleware('throttle:30,1')->name('jobs.sms');
        Route::get('jobs/{job}/booking-confirmation', [BookingConfirmationController::class, 'show'])->name('jobs.booking-confirmation.show');
        Route::post('jobs/{job}/booking-confirmation', [BookingConfirmationController::class, 'send'])->middleware('throttle:30,1')->name('jobs.booking-confirmation.send');
        Route::post('jobs/{job}/finish-booking', [BookingConfirmationController::class, 'finish'])->middleware('throttle:30,1')->name('jobs.finish-booking');
        Route::post('jobs/{job}/messages/opened', [JobMessageController::class, 'opened'])->name('jobs.messages.opened');

        // Connecting the company's own payment provider account (OAuth). The callback URL is registered at the provider.
        Route::get('payment-providers/{provider}/connect', [PaymentProviderController::class, 'connect'])->name('payment-providers.connect');
        Route::get('payment-providers/{provider}/callback', [PaymentProviderController::class, 'callback'])->name('payment-providers.callback');
        Route::get('payment-providers/square/locations', [PaymentProviderController::class, 'locations'])->name('payment-providers.square.locations');
        Route::put('payment-providers/square/location', [PaymentProviderController::class, 'location'])->name('payment-providers.square.location');
        Route::delete('payment-providers/{provider}', [PaymentProviderController::class, 'disconnect'])->name('payment-providers.disconnect');

        // Import of customers and invoice history from Square (Owner only).
        Route::get('square-import', [SquareImportController::class, 'show'])->name('square-import.show');
        Route::post('square-import', [SquareImportController::class, 'start'])->name('square-import.start');
        Route::get('square-import/{import}/status', [SquareImportController::class, 'status'])->name('square-import.status');
        Route::post('square-import/{import}/run', [SquareImportController::class, 'run'])->name('square-import.run');
        Route::delete('square-import/{import}', [SquareImportController::class, 'discard'])->name('square-import.discard');
        Route::post('square-import/{import}/undo', [SquareImportController::class, 'undo'])->name('square-import.undo');

        // The UI kit, to check every component and state on a phone and a desktop. Local development only.
        if (app()->environment('local')) {
            Route::inertia('dev/ui', 'dev/ui')->name('dev.ui');
        }

        Route::put('settings/menu-mode', MenuModeController::class)->name('menu-mode.update');
        Route::get('expenses-and-taxes', ExpensesHubController::class)->name('expenses-hub');
        // Tax reserve (UX-REDESIGN §17), Owner only.
        Route::get('expenses-and-taxes/tax-reserve', [TaxReserveController::class, 'index'])->name('tax-reserve.index');
        Route::put('expenses-and-taxes/tax-reserve/settings', [TaxReserveController::class, 'settings'])->name('tax-reserve.settings');
        Route::post('expenses-and-taxes/tax-reserve/transfers', [TaxReserveController::class, 'store'])->name('tax-reserve.transfers.store');
        Route::delete('expenses-and-taxes/tax-reserve/transfers/{transfer}', [TaxReserveController::class, 'destroy'])->name('tax-reserve.transfers.destroy');
        Route::get('expenses-and-taxes/tax-reserve/export.csv', [TaxReserveController::class, 'export'])->name('tax-reserve.export');

        // Data protection: deleted items (30 days, restorable) and the change log (Owner).
        Route::get('deleted-items', [TrashController::class, 'index'])->name('trash.index');
        Route::post('deleted-items/{type}/{id}/restore', [TrashController::class, 'restore'])->whereNumber('id')->name('trash.restore');
        Route::get('history/{type}/{id}', [HistoryController::class, 'show'])->whereNumber('id')->name('history.show');

        Route::prefix('company')->group(function () {
            Route::get('change-log', [HistoryController::class, 'index'])->name('company.change-log');
            Route::get('booking-bot', [BotTokenController::class, 'index'])->name('company.booking-bot');
            Route::post('booking-bot', [BotTokenController::class, 'store'])->middleware('throttle:10,1')->name('company.booking-bot.store');
            Route::delete('booking-bot/{token}', [BotTokenController::class, 'destroy'])->name('company.booking-bot.destroy');
            Route::get('settings', [CompanySettingsController::class, 'edit'])->name('company.settings.edit');
            Route::get('settings/{section}', [CompanySettingsController::class, 'edit'])->name('company.settings.section');
            Route::put('settings', [CompanySettingsController::class, 'update'])->name('company.settings.update');
            Route::put('work-mode', WorkModeController::class)->name('company.work-mode');
            Route::put('timezone', DetectTimezoneController::class)->name('company.timezone.detect');
            Route::get('messaging', [MessagingSettingsController::class, 'edit'])->name('company.messaging.edit');
            Route::put('messaging', [MessagingSettingsController::class, 'update'])->name('company.messaging.update');
            Route::post('messaging/number', [MessagingSettingsController::class, 'provision'])->middleware('throttle:5,1')->name('company.messaging.provision');
            Route::put('messaging/registration', [MessagingSettingsController::class, 'registration'])->name('company.messaging.registration');
            Route::get('auto-messages', [AutoMessagesController::class, 'edit'])->name('company.auto-messages.edit');
            Route::put('auto-messages', [AutoMessagesController::class, 'update'])->name('company.auto-messages.update');
            // Morning SMS to the owner's phone and the nightly Google Drive backup (owner's task of November 19).
            Route::get('morning', [MorningController::class, 'edit'])->name('company.morning.edit');
            Route::get('answers', [AnswersController::class, 'edit'])->name('company.answers.edit');
            Route::get('suppliers', [SupplierController::class, 'index'])->name('company.suppliers.index');
            Route::post('suppliers', [SupplierController::class, 'store'])->name('company.suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('company.suppliers.update');
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('company.suppliers.destroy');
            Route::put('answers', [AnswersController::class, 'update'])->name('company.answers.update');
            Route::put('morning', [MorningController::class, 'update'])->name('company.morning.update');
            Route::post('google-drive/connect', [MorningController::class, 'connect'])->name('google-drive.connect');
            Route::delete('google-drive', [MorningController::class, 'disconnect'])->name('google-drive.disconnect');
            Route::get('service-area', [ServiceAreaController::class, 'edit'])->name('company.service-area.edit');
            Route::put('service-area', [ServiceAreaController::class, 'update'])->name('company.service-area.update');
            Route::post('service-area/reset', [ServiceAreaController::class, 'reset'])->name('company.service-area.reset');
            Route::get('google-reviews', [GoogleProfileController::class, 'edit'])->name('company.google-profiles.edit');
            Route::put('google-reviews', [GoogleProfileController::class, 'update'])->name('company.google-profiles.update');
            Route::post('google-reviews/image', [GoogleProfileController::class, 'storeImage'])->name('company.google-profiles.image.store');
            Route::delete('google-reviews/image', [GoogleProfileController::class, 'destroyImage'])->name('company.google-profiles.image.destroy');
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
