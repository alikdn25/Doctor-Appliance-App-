<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CashLedger;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\RefundInvoice;
use App\Actions\Billing\SyncInvoice;
use App\Actions\Billing\VoidBillingRecord;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\DocumentRequest;
use App\Http\Requests\Billing\PaymentRequest;
use App\Messaging\ReviewRequests;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\Billing\MoneyInput;
use App\Support\Locale\Currencies;
use App\Support\PrivateMedia;
use App\Support\Ui\SavedToast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request, Invoice $invoice, RecordPayment $record, CashLedger $cash): RedirectResponse
    {
        // Nothing left to pay and no amount typed: nothing to record.
        if ($request->amount() <= 0) {
            SavedToast::flash(route('invoices.show', $invoice));

            return back();
        }

        $payment = DB::transaction(function () use ($request, $invoice, $record, $cash) {
            $payment = $record->manual(
                $invoice,
                $request->amount(),
                $request->paymentMethod(),
                $request->validated('reference'),
                $request->validated('note'),
                $request->receivedAt(),
                $request->user(),
            );

            // Optional photo of the receipt given to the customer.
            if ($request->hasFile('receipt')) {
                $payment->forceFill(['receipt_path' => $request->file('receipt')->store(
                    "companies/{$invoice->company_id}/payments", PrivateMedia::diskName(),
                )])->save();
            }

            // Cash is now on hand with the person who took it.
            if ($payment->method === PaymentMethod::Cash) {
                $cash->collected($payment, $request->user());
            }

            return $payment;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('payments.recorded', [
            'amount' => Money::format($payment->amount, $payment->currency),
            'method' => $payment->method->label(),
        ])]);

        $invoice->refresh();
        if ($invoice->balance <= 0) {
            Inertia::flash('paid_in_full', $this->paidInFull($invoice, $request->user()));
        }

        return back();
    }

    /**
     * After an invoice is paid in full: ask whether to send the Google review request, then offer to close the job
     * in one tap (or to finish the visit still under way).
     *
     * @return array<string, mixed>
     */
    private function paidInFull(Invoice $invoice, User $user): array
    {
        $job = $invoice->job()->firstOrFail();
        $open = $job->closed_at === null && $job->outcome === null && Gate::forUser($user)->allows('work', $job)
            && ! in_array($job->status, [JobStatus::Completed, JobStatus::Invoiced, JobStatus::AwaitingPayment, JobStatus::Paid, JobStatus::Cancelled], true);
        $visit = $open ? $job->visits()->whereIn('status', [VisitStatus::OnTheWay->value, VisitStatus::InProgress->value])->first() : null;

        return [
            'invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'job_id' => $job->id,
            'complete' => match (true) {
                ! $open => null,
                $visit !== null => ['finish_url' => route('visits.finish-screen', $visit)],
                default => ['close_url' => route('jobs.close', $job)],
            },
            'review' => app(ReviewRequests::class)->prompt($invoice),
        ];
    }

    /**
     * A refund as settled (the customer does not owe it again), with the reason.
     */
    public function refund(Request $request, Invoice $invoice, RefundInvoice $refund): RedirectResponse
    {
        Gate::authorize('refund', $invoice);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', DocumentRequest::moneyRule($invoice->currency)],
            'reason' => ['required', 'string', 'max:500'],
        ], [], ['amount' => __('payments.fields.amount'), 'reason' => __('payments.refunds.reason')]);

        $refund->handle($invoice, Currencies::toMinor($data['amount'], $invoice->currency), $data['reason'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('payments.refunds.done')]);

        return back();
    }

    /**
     * The photo of a cash receipt (staff who can see the invoice).
     */
    public function receipt(Payment $payment): StreamedResponse
    {
        Gate::authorize('view', $payment->invoice);
        abort_if($payment->receipt_path === null, 404);

        return PrivateMedia::response($payment->receipt_path);
    }

    public function void(Request $request, Payment $payment, VoidBillingRecord $void, CashLedger $cash): RedirectResponse
    {
        Gate::authorize('void', $payment);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $void->payment($payment, $request->user(), $reason);
        $cash->paymentVoided($payment->fresh(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('payments.voided')]);

        return back();
    }

    /**
     * Corrects a saved payment at any time (owner's task of November 25): amount, method, date, reference and note.
     * What came from the payment provider (a Square card payment: its amount, method and transaction) stays as it
     * came; its date and note can still be corrected. The invoice's balance is worked out again.
     */
    public function update(Request $request, Payment $payment, SyncInvoice $sync, CashLedger $cash): RedirectResponse
    {
        Gate::authorize('edit', $payment);
        $invoice = $payment->invoice()->firstOrFail();
        $fromProvider = $payment->provider !== null;

        $request->replace(MoneyInput::cleanPaths($request->input(), ['amount']));
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', DocumentRequest::moneyRule($payment->currency)],
            'method' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases()))],
            'received_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'amount' => __('payments.fields.amount'),
            'method' => __('payments.fields.method'),
            'received_on' => __('payments.fields.received_on'),
        ]);

        DB::transaction(function () use ($request, $data, $payment, $invoice, $fromProvider, $sync, $cash) {
            $wasCash = $payment->method === PaymentMethod::Cash;

            if (! $fromProvider) {
                if ($request->has('amount') && filled($data['amount'] ?? null)) {
                    $payment->amount = Currencies::toMinor($data['amount'], $payment->currency);
                }
                if ($request->has('method')) {
                    $payment->method = PaymentMethod::tryFrom((string) ($data['method'] ?? '')) ?? PaymentMethod::Other;
                }
                if ($request->has('reference')) {
                    $payment->reference = filled($data['reference'] ?? null) ? trim($data['reference']) : null;
                }
            }
            if ($request->has('note')) {
                $payment->note = filled($data['note'] ?? null) ? trim($data['note']) : null;
            }
            if (filled($data['received_on'] ?? null)) {
                $timezone = currentCompany()->timezone;
                $time = $payment->received_at->copy()->setTimezone($timezone)->format('H:i:s');
                $payment->received_at = CarbonImmutable::parse($data['received_on'].' '.$time, $timezone)->utc();
            }

            if (! $payment->isDirty()) {
                return;
            }
            $moneyChanged = $payment->isDirty(['amount', 'method', 'received_at']);
            $payment->save();

            // Cash on hand follows the corrected payment.
            if ($moneyChanged && ($wasCash || $payment->method === PaymentMethod::Cash)) {
                $cash->paymentVoided($payment, $request->user());
                if ($payment->method === PaymentMethod::Cash && $payment->voided_at === null) {
                    $cash->collected($payment, $payment->user ?? $request->user());
                }
            }

            $sync->handle($invoice->refresh(), $request->user());
        });

        SavedToast::flash(route('invoices.show', $invoice), __('payments.updated'));

        return back();
    }
}
