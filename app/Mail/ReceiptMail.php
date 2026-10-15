<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\DocumentPdf;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\Money;
use App\Support\Billing\PublicDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Receipt after payment, in the style of a card-terminal receipt (UX-REDESIGN §19): the brand banner, the amount
 * and "Paid", the date and the way it was paid, the lines and totals, the brand's contacts, "View receipt online"
 * and the PDF (attached and as a button). Sent only when someone taps "Send receipt" (queued).
 */
class ReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $invoiceId;

    public int $companyId;

    /**
     * Only ids are queued: tenant-scoped models cannot be restored outside their company.
     */
    public function __construct(Invoice $invoice)
    {
        $this->invoiceId = $invoice->id;
        $this->companyId = $invoice->company_id;
    }

    public function invoice(): Invoice
    {
        return $this->inCompany(fn () => Invoice::query()->withTrashed()->with(['brand', 'payments'])->findOrFail($this->invoiceId));
    }

    public function envelope(): Envelope
    {
        return $this->inCompany(function () {
            $invoice = $this->invoice();
            $brand = $invoice->brand;
            $brandName = $brand?->name ?? $this->company()->name;
            $replyTo = $brand?->sender_email ?: $brand?->email;

            return new Envelope(
                from: new Address((string) config('mail.from.address'), $brand?->sender_name ?: $brandName),
                replyTo: $replyTo ? [new Address($replyTo, $brand?->sender_name ?: $brandName)] : [],
                subject: __('documents.receipt.subject', ['brand' => $brandName, 'number' => $invoice->number]),
            );
        });
    }

    public function content(): Content
    {
        return $this->inCompany(function () {
            $invoice = $this->invoice();
            $company = $this->company();
            $payment = $this->lastPayment($invoice);

            return new Content(view: 'mail.receipt', with: [
                'print' => DocumentPrint::data($invoice),
                'paidAmount' => Money::format($invoice->amount_paid, $invoice->currency, $company->locale),
                'paidOn' => DocumentPrint::date($payment?->received_at ?? $invoice->paid_at ?? now()),
                'method' => $payment ? ($payment->provider ? __('payments.online', ['provider' => $payment->provider]) : $payment->method->label()) : null,
                'reference' => $payment?->reference,
                'url' => PublicDocument::url($invoice),
                'pdfUrl' => route('documents.public.pdf', PublicDocument::token($invoice)),
            ]);
        });
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => $this->inCompany(fn () => DocumentPdf::render($this->invoice())),
                $this->inCompany(fn () => DocumentPdf::filename($this->invoice())),
            )->withMime('application/pdf'),
        ];
    }

    private function lastPayment(Invoice $invoice): ?Payment
    {
        return $invoice->payments
            ->whereNull('voided_at')
            ->where('amount', '>', 0)
            ->sortByDesc('received_at')
            ->first();
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    /**
     * Queued mail runs without a tenant: everything is rendered inside the invoice's company.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inCompany(callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($this->company(), $callback(...));
    }
}
