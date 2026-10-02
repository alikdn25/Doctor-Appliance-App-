<?php

namespace App\Actions\Jobs;

use App\Actions\Billing\RefundInvoice;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * After a warranty callback the customer declined, or that could not be repaired: money back on the original job's
 * invoices (full or partial), through the refund module. Must run in a tenant context.
 */
class RefundOriginalJob
{
    public function __construct(private readonly RefundInvoice $refunds) {}

    /**
     * What can still be refunded on the original job (paid, not yet refunded), per currency of its first invoice.
     */
    public static function refundable(ServiceJob $original): int
    {
        return (int) $original->invoices()->where('status', '!=', InvoiceStatus::Void->value)->sum('amount_paid');
    }

    /**
     * @param  'full'|'partial'  $mode
     */
    public function handle(ServiceJob $callback, string $mode, ?int $amount, string $reason, User $user): void
    {
        $original = $callback->previousJob()->first();

        if ($original === null) {
            throw ValidationException::withMessages(['refund' => __('jobs.errors.previous_job_required')]);
        }

        $available = self::refundable($original);
        $amount = $mode === 'full' ? $available : (int) $amount;

        if ($amount <= 0 || $amount > $available) {
            throw ValidationException::withMessages(['refund_amount' => __('payments.refunds.more_than_paid')]);
        }

        $invoices = $original->invoices()->where('status', '!=', InvoiceStatus::Void->value)
            ->where('amount_paid', '>', 0)->orderByDesc('id')->get();

        foreach ($invoices as $invoice) {
            /** @var Invoice $invoice */
            if ($amount <= 0) {
                break;
            }

            $part = min($amount, $invoice->amount_paid);
            $this->refunds->handle($invoice, $part, $reason, $user);
            $amount -= $part;
        }
    }
}
