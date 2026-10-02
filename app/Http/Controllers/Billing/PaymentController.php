<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\VoidBillingRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request, Invoice $invoice, RecordPayment $record): RedirectResponse
    {
        $payment = $record->manual(
            $invoice,
            $request->amount(),
            $request->paymentMethod(),
            $request->validated('reference'),
            $request->validated('note'),
            $request->receivedAt(),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('payments.recorded', [
            'amount' => Money::format($payment->amount, $payment->currency),
            'method' => $payment->method->label(),
        ])]);

        return back();
    }

    public function void(Request $request, Payment $payment, VoidBillingRecord $void): RedirectResponse
    {
        Gate::authorize('void', $payment);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $void->payment($payment, $request->user(), $reason);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('payments.voided')]);

        return back();
    }
}
