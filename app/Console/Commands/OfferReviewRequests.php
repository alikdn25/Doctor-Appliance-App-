<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReviewRequest;
use App\Models\User;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Two hours after an invoice is marked paid: a notification "Send a review request?" (owner's task of November 18)
 * to the person who took the payment (else the Owners). It opens the invoice with the review request ready; the
 * text can be edited and nothing goes before Send. Never sent automatically. Once per invoice; skipped when the job
 * already had a request or there is no Google profile. The request itself goes by email.
 */
#[Signature('invoices:paid-followup')]
#[Description('Offer a review request 2 hours after an invoice is paid')]
class OfferReviewRequests extends Command
{
    public const DELAY_HOURS = 2;

    public function handle(CurrentCompany $tenancy): int
    {
        $count = 0;
        $companyIds = Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Paid->value)->whereNull('review_offered_at')
            ->whereBetween('paid_at', [now()->subDays(2), now()->subHours(self::DELAY_HOURS)])->distinct()->pluck('company_id');

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $tenancy->runAs($company, function () use ($company, &$count) {
                $invoices = Invoice::query()->with('customer')->where('status', InvoiceStatus::Paid->value)->whereNull('review_offered_at')
                    ->whereBetween('paid_at', [now()->subDays(2), now()->subHours(self::DELAY_HOURS)])->get();

                foreach ($invoices as $invoice) {
                    $invoice->forceFill(['review_offered_at' => now()])->save();

                    if (GoogleProfile::query()->doesntExist()
                        || ReviewRequest::query()->where('service_job_id', $invoice->service_job_id)->where('status', ReviewRequest::SENT)->exists()) {
                        continue;
                    }

                    $alert = new AppAlert('review_offer',
                        __('automation.review.title', ['customer' => $invoice->customer?->display_name ?? '']),
                        __('automation.review.body', ['number' => $invoice->number]),
                        route('invoices.show', ['invoice' => $invoice, 'review' => 1]),
                        mail: false,
                    );
                    $payer = User::query()->find(Payment::query()->where('invoice_id', $invoice->id)->latest('id')->value('user_id'));

                    try {
                        $payer !== null && $payer->is_active ? $payer->notify($alert) : Alerts::owners($company, $alert);
                        $count++;
                    } catch (Throwable) {
                        // One broken channel must not stop the others.
                    }
                }
            });
        }

        $this->info("Review requests offered: {$count}");

        return self::SUCCESS;
    }
}
