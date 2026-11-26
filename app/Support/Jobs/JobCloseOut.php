<?php

namespace App\Support\Jobs;

use App\Actions\Billing\CashLedger;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Jobs\CloseJob;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Jobs\SendCloseOutEmails;
use App\Messaging\ReviewRequests;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Payments\ChargeCheckout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Done" of the Close job window and of the "Receipt & review" step (owner's task of November 26): the payment is
 * recorded (Cash / E-transfer on the open invoice, or on an invoice made from the lines typed), the open visit is
 * finished, the job is Completed (then Paid by its invoices), and the emails chosen go from the queue afterwards.
 * The same request sent twice (double tap, retry) is applied once: no second payment, invoice or email. Must run in a
 * tenant context.
 */
class JobCloseOut
{
    public const METHODS = ['cash', 'bank_transfer', 'card_reader', 'no_charge'];

    public function __construct(
        private readonly CloseJob $close,
        private readonly RecordPayment $record,
        private readonly SaveBillingDocument $documents,
        private readonly CashLedger $cash,
    ) {}

    /**
     * The invoice the window takes payment for: the newest with money owed.
     */
    public static function openInvoice(ServiceJob $job): ?Invoice
    {
        return Invoice::query()->where('service_job_id', $job->id)->whereNull('voided_at')
            ->whereNotIn('status', [InvoiceStatus::Void->value])
            ->where('balance', '>', 0)->latest('id')->first();
    }

    /**
     * An invoice made from the lines typed in the window (amounts without tax; taxes by code). Null when nothing was
     * typed.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function invoiceFromLines(ServiceJob $job, array $lines, bool $amountsIncludeTax, User $user): ?Invoice
    {
        $totals = ChargeCheckout::totals($lines, $amountsIncludeTax);
        if ($totals['total'] <= 0) {
            return null;
        }

        return $this->documents->createInvoice($job, [
            'issued_on' => CarbonImmutable::now(currentCompany()->timezone)->toDateString(),
            'due_on' => null,
            'notes' => null,
            'tax_rate_ids' => $totals['tax_rate_ids'],
            'items' => array_map(fn (array $line) => [
                'description' => $line['description'],
                'quantity' => '1',
                'unit_price' => $line['unit_price'],
                'taxable' => $line['tax_rate_ids'] !== [],
                'tax_rate_ids' => $line['tax_rate_ids'],
                'kind' => LineKind::Service->value,
            ], $totals['lines']),
        ], $user);
    }

    /**
     * "Done" of the Close job window.
     *
     * @param  array<string, mixed>  $data  token, method, payment_id, lines, amounts_include_tax, note, send_receipt,
     *                                      request_review, google_profile_id, email
     */
    public function finish(ServiceJob $job, User $user, array $data): void
    {
        $done = DB::transaction(function () use ($job, $user, $data) {
            $job = ServiceJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($job->close_request !== null && $job->close_request === $data['token']) {
                return null;
            }

            $method = (string) $data['method'];
            $invoice = null;
            $payment = null;

            if (in_array($method, ['cash', 'bank_transfer'], true)) {
                $invoice = self::openInvoice($job)
                    ?? (self::billed($job) ? null : $this->invoiceFromLines($job, (array) ($data['lines'] ?? []), (bool) ($data['amounts_include_tax'] ?? false), $user));

                if ($invoice !== null && $invoice->balance > 0) {
                    $payment = $this->record->manual($invoice, $invoice->balance, PaymentMethod::from($method), null, null, now(), $user);
                    if ($payment->method === PaymentMethod::Cash) {
                        $this->cash->collected($payment, $user);
                    }
                }
            } elseif ($method === 'card_reader') {
                // The card was charged before this step; the payment must be one of this job's.
                $payment = Payment::query()->valid()->whereKey((int) ($data['payment_id'] ?? 0))
                    ->whereIn('invoice_id', Invoice::query()->where('service_job_id', $job->id)->select('id'))
                    ->first() ?? throw ValidationException::withMessages(['payment_id' => __('jobs.close_out.no_payment')]);
                $invoice = $payment->invoice()->first();
            }

            $this->closeJob($job->refresh(), $user, $method === 'no_charge' && ! self::billed($job) ? JobOutcome::NoCharge : JobOutcome::Repaired, $data['note'] ?? null);
            $job->forceFill(['close_request' => $data['token']])->save();

            return [$job, $invoice, $payment];
        });

        if ($done === null) {
            return;
        }

        [$job, $invoice, $payment] = $done;
        $this->queueEmails($job, $payment !== null ? $invoice : null, $user, $data);
    }

    /**
     * "Done" of the "Receipt & review" step after a payment taken elsewhere (Take payment, the Paid screen of an
     * online payment): the job is closed when it is not yet, and the emails chosen are queued, once per request.
     *
     * @param  array<string, mixed>  $data  token, send_receipt, request_review, google_profile_id, email
     */
    public function afterPayment(Payment $payment, User $user, array $data): void
    {
        $invoice = $payment->invoice()->firstOrFail();
        $job = $invoice->job()->first();

        if ($job === null) {
            return;
        }

        $done = DB::transaction(function () use ($job, $user, $data, $payment) {
            $job = ServiceJob::query()->lockForUpdate()->findOrFail($job->id);

            if ($job->close_request !== null && $job->close_request === $data['token']) {
                return null;
            }

            // Reopened or booked again after this payment: the job is on again; the payment does not close it.
            $paidAt = $payment->created_at ?? now();
            $later = ($job->reopened_at !== null && $job->reopened_at->gt($paidAt))
                || $job->visits()->where('created_at', '>', $paidAt)->where('status', '!=', 'cancelled')->exists();
            if (! $later && ! in_array($job->status, [JobStatus::Cancelled, JobStatus::Draft], true)) {
                $this->closeJob($job, $user, JobOutcome::Repaired, null);
            }
            $job->forceFill(['close_request' => $data['token']])->save();

            return $job;
        });

        if ($done !== null) {
            $this->queueEmails($done, $invoice, $user, $data);
        }
    }

    /**
     * Money billed on the job (an invoice with an amount that is not void).
     */
    private static function billed(ServiceJob $job): bool
    {
        return $job->invoices()->whereNotIn('status', [InvoiceStatus::Void->value, InvoiceStatus::Draft->value])->where('total', '>', 0)->exists();
    }

    private function closeJob(ServiceJob $job, User $user, JobOutcome $outcome, ?string $note): void
    {
        $note = trim((string) $note);
        if ($note !== '') {
            // The note goes to Work done, as with Close by voice.
            $job->tech_notes = trim(implode("\n", array_filter([$job->tech_notes, $note])));
            $job->save();
        }

        // A payment may have closed it already (paid in full).
        if ($job->outcome === null) {
            $this->close->close($job, $outcome, null, null, $user);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function queueEmails(ServiceJob $job, ?Invoice $invoice, User $user, array $data): void
    {
        $receipt = (bool) ($data['send_receipt'] ?? false) && $invoice !== null;
        $review = (bool) ($data['request_review'] ?? false);
        $profile = $review ? GoogleProfile::query()->find($data['google_profile_id'] ?? null) : null;

        if ($profile !== null && (int) $user->review_profile_id !== $profile->id) {
            $user->forceFill(['review_profile_id' => $profile->id])->saveQuietly();
        }

        if (! $receipt && $profile === null) {
            return;
        }

        $customer = $job->customer()->first();
        if ($customer === null || $customer->is_walk_in) {
            return;
        }

        $typed = filter_var(mb_strtolower(trim((string) ($data['email'] ?? ''))), FILTER_VALIDATE_EMAIL) ?: null;
        if ($typed !== null) {
            ReviewRequests::keepEmail($customer, $typed);
        }
        $email = $typed ?? $customer->primaryEmail()->value('email') ?? $customer->emails()->value('email');

        // No email: nothing goes; the job is closed all the same.
        if ($email === null) {
            return;
        }

        SendCloseOutEmails::dispatch(currentCompany()->id, $job->id, $receipt ? $invoice?->id : null, $user->id, $email, $receipt, $profile?->id)->afterCommit();
    }
}
