<?php

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\SendDocument;
use App\Actions\Jobs\MarkEstimateSent;
use App\Enums\EstimateStatus;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Jobs\JobMessageController;
use App\Messaging\MessagingPresenter;
use App\Messaging\Messenger;
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
        // A replaced version is history; the newest version is the one to send.
        abort_if($estimate->status === EstimateStatus::Revised, 422);

        return $this->send($request, $estimate, $send);
    }

    public function sendInvoice(Request $request, Invoice $invoice, SendDocument $send): RedirectResponse
    {
        abort_if($invoice->isVoid(), 422);

        return $this->send($request, $invoice, $send);
    }

    public function smsEstimate(Request $request, Estimate $estimate, Messenger $messenger): RedirectResponse
    {
        abort_if($estimate->status === EstimateStatus::Revised, 422);

        return $this->sms($request, $estimate, $messenger);
    }

    public function smsInvoice(Request $request, Invoice $invoice, Messenger $messenger): RedirectResponse
    {
        abort_if($invoice->isVoid(), 422);

        return $this->sms($request, $invoice, $messenger);
    }

    /**
     * "Send by SMS" in Automatic mode: the document's link by text from the company number.
     */
    private function sms(Request $request, Estimate|Invoice $document, Messenger $messenger): RedirectResponse
    {
        Gate::authorize('view', $document);
        abort_unless(currentCompany()->sms_mode === SmsMode::Automatic, 404);

        $document->loadMissing(['customer', 'job']);
        $message = $messenger->sms(
            $document instanceof Invoice ? MessageKind::InvoiceLink : MessageKind::EstimateLink,
            $document->customer,
            $document->job,
            MessagingPresenter::documentText($document),
            $request->user(),
        );

        if ($message->status !== 'blocked') {
            $document->forceFill(['sent_at' => now(), 'sent_to' => $message->to,
                ...($document instanceof Estimate ? ['followup_processed_at' => null] : []),
            ])->saveQuietly();
            if ($document instanceof Estimate) {
                app(MarkEstimateSent::class)->handle($document->job, $request->user());
            }
        }

        return JobMessageController::result($message->status, $message->status_reason, $message->send_after);
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
        if ($document instanceof Estimate) {
            app(MarkEstimateSent::class)->handle($document->job, $request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('documents.sent', [
            'kind' => __($document instanceof Invoice ? 'documents.invoice' : 'documents.estimate'),
            'email' => $data['email'],
        ])]);

        return back();
    }
}
