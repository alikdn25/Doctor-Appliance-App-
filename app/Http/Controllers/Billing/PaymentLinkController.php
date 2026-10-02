<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\CreateInvoicePaymentLink;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Payments\PaymentProviderException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "Pay online": a provider payment link for the invoice balance, shown as a link and a QR code.
 */
class PaymentLinkController extends Controller
{
    public function store(Request $request, Invoice $invoice, CreateInvoicePaymentLink $create): RedirectResponse
    {
        Gate::authorize('recordPayment', $invoice);

        try {
            $create->handle($invoice, $request->user());
        } catch (PaymentProviderException $e) {
            throw ValidationException::withMessages(['payment_link' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('payments.links.created')]);

        return back();
    }
}
