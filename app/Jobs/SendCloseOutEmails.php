<?php

namespace App\Jobs;

use App\Messaging\ReviewRequests;
use App\Models\Company;
use App\Models\Customer;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\PublicDocument;
use App\Support\Payments\PaymentReceipt;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The emails chosen on the "Receipt & review" step (owner's task of November 26), sent from the queue after the
 * person has moved on: both switches → one email (the receipt with the review block); Send receipt only → the
 * receipt; Request review only → the review request. Nothing without an email.
 */
class SendCloseOutEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $companyId,
        public int $jobId,
        public ?int $invoiceId,
        public ?int $userId,
        public string $email,
        public bool $receipt,
        public ?int $profileId,
    ) {}

    public function handle(CurrentCompany $tenancy, PaymentReceipt $receipts, ReviewRequests $reviews): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($receipts, $reviews) {
            $job = ServiceJob::query()->with(['customer', 'brand'])->find($this->jobId);
            $customer = $job?->customer;
            if ($job === null || ! $customer instanceof Customer) {
                return;
            }

            $user = $this->userId !== null ? User::query()->find($this->userId) : null;
            $profile = $this->profileId !== null ? GoogleProfile::query()->find($this->profileId) : null;
            $invoice = $this->invoiceId !== null ? Invoice::query()->with(['customer', 'job.brand', 'brand'])->find($this->invoiceId) : null;

            if ($this->receipt && $invoice !== null) {
                PublicDocument::token($invoice);
                $text = $profile !== null ? ReviewRequests::text($customer, $job->brand?->name ?? $invoice->brand?->name, $profile) : null;
                $receipts->deliver($invoice, $this->email, $profile, $text, $user);

                return;
            }

            if ($profile !== null) {
                $reviews->sendForJob($job, $customer, $profile, $this->email, $user);
            }
        });
    }
}
