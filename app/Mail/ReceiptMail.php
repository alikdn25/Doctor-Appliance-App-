<?php

namespace App\Mail;

use App\Enums\PaymentMethod;
use App\Models\Brand;
use App\Models\Company;
use App\Models\GoogleProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Billing\DocumentPdf;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\Money;
use App\Support\Billing\PublicDocument;
use App\Support\Mail\MailSender;
use App\Support\Reviews\ReviewImage;
use App\Support\Reviews\ReviewText;
use App\Support\Reviews\ReviewTracking;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
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
    public function __construct(Invoice $invoice, public ?int $reviewProfileId = null, public ?string $reviewText = null)
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

            return new Envelope(
                from: MailSender::from($brand, $this->company()),
                replyTo: MailSender::replyTo($brand, $this->company()),
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
                'method' => $payment ? ($payment->provider && $payment->method === PaymentMethod::Online ? __('payments.online', ['provider' => $payment->provider]) : $payment->method->label()) : null,
                'reference' => $payment?->reference,
                'url' => PublicDocument::url($invoice),
                'pdfUrl' => route('documents.public.pdf', PublicDocument::token($invoice)),
                'review' => $this->review($invoice),
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

    /**
     * "Request review" ticked on the Paid screen: the review block (picture, stars, link) with the review request
     * text sent from there and the chosen location's link.
     *
     * @return array{url: string, image: string|null, text: string|null}|null
     */
    private function review(Invoice $invoice): ?array
    {
        $profile = $this->reviewProfileId === null ? null : GoogleProfile::query()->find($this->reviewProfileId);
        // Through our address, so a click is seen on the job (no review reminder after it).
        $job = $invoice->job()->first();
        $url = $profile === null ? null : ReviewTracking::link($job, $profile);
        $text = filled($this->reviewText) && $profile !== null ? ReviewTracking::inText($this->reviewText, $job, $profile) : null;

        return filled($url) ? [
            'url' => $url,
            'image' => ReviewImage::url($this->company()),
            'text' => $text !== null ? ReviewText::html($text, Brand::readableColor($invoice->brand?->primary_color)) : null,
        ] : null;
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
