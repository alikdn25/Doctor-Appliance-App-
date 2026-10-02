<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\SendDocument;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Billing\DocumentPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * PDF download and sending by email, for estimates and invoices alike.
 * Everyone who can see the document can do both (office, and technicians on their jobs).
 */
class DocumentDeliveryController extends Controller
{
    public function estimatePdf(Estimate $estimate): Response
    {
        return $this->pdf($estimate);
    }

    public function invoicePdf(Invoice $invoice): Response
    {
        return $this->pdf($invoice);
    }

    public function sendEstimate(Request $request, Estimate $estimate, SendDocument $send): RedirectResponse
    {
        return $this->send($request, $estimate, $send);
    }

    public function sendInvoice(Request $request, Invoice $invoice, SendDocument $send): RedirectResponse
    {
        abort_if($invoice->isVoid(), 422);

        return $this->send($request, $invoice, $send);
    }

    private function pdf(Estimate|Invoice $document): Response
    {
        Gate::authorize('view', $document);

        return response(DocumentPdf::render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.DocumentPdf::filename($document).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function send(Request $request, Estimate|Invoice $document, SendDocument $send): RedirectResponse
    {
        Gate::authorize('view', $document);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ], [], ['email' => __('documents.to'), 'message' => __('documents.message')]);

        $send->handle($document, $data['email'], $data['message'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('documents.sent', [
            'kind' => __($document instanceof Invoice ? 'documents.invoice' : 'documents.estimate'),
            'email' => $data['email'],
        ])]);

        return back();
    }
}
