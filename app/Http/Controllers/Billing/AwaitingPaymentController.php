<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\SyncJobBillingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\Billing\Money;
use App\Support\Jobs\JobPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Awaiting payment" (owner's task of November 25), opened from the orange chip of the top strip: the sent invoices
 * of jobs waiting for their money, oldest sent first. Each row: customer, invoice number, amount owed, days since it
 * was sent and Sent / Opened {time} / Paid. A tap opens the job. A job paid in full leaves the list by itself.
 */
class AwaitingPaymentController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Invoice::class);
        $company = currentCompany();
        $user = $request->user();
        $now = CarbonImmutable::now();

        $invoices = Invoice::query()->with('customer')
            ->whereNotNull('sent_at')
            ->where('status', '!=', InvoiceStatus::Void->value)
            ->whereHas('job', fn ($q) => $q->visibleTo($user)->where('status', JobStatus::AwaitingPayment->value)->whereNull('deleted_at'))
            ->orderBy('sent_at')->orderBy('id')
            ->limit(300)->get();

        return Inertia::render('invoices/awaiting-payment', [
            'rows' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'job_id' => $invoice->service_job_id,
                'number' => $invoice->number,
                'customer' => $invoice->customer?->display_name,
                'balance' => Money::format(max(0, $invoice->balance), $invoice->currency, $company->locale),
                'days' => (int) CarbonImmutable::instance($invoice->sent_at)->diffInDays($now),
                'state' => match (true) {
                    SyncJobBillingStatus::settled($invoice) || $invoice->balance <= 0 => 'paid',
                    $invoice->first_opened_at !== null => 'opened',
                    default => 'sent',
                },
                'opened_at' => JobPresenter::iso($invoice->first_opened_at),
                'sent_at' => JobPresenter::iso($invoice->sent_at),
            ])->values(),
            'jobs' => $invoices->pluck('service_job_id')->unique()->count(),
        ]);
    }
}
