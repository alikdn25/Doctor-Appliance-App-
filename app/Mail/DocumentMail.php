<?php

namespace App\Mail;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Billing\DocumentPdf;
use App\Support\Billing\PublicDocument;
use App\Support\Mail\MailSender;
use App\Support\Reviews\ReviewImage;
use App\Support\Reviews\ReviewLink;
use App\Support\Reviews\ReviewTracking;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An estimate or invoice sent to the customer: the message typed by the user, a button to the online page and
 * the PDF attached. Sent in the name of the document's brand (queued).
 */
class DocumentMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /** @var class-string<Estimate|Invoice> */
    public string $documentClass;

    public int $documentId;

    public int $companyId;

    /**
     * Only ids are queued: tenant-scoped models cannot be restored outside their company.
     */
    public function __construct(Estimate|Invoice $document, public string $messageText)
    {
        $this->documentClass = $document::class;
        $this->documentId = $document->id;
        $this->companyId = $document->company_id;
    }

    public function document(): Estimate|Invoice
    {
        return $this->inCompany(fn () => $this->documentClass::query()->withTrashed()->with('brand')->findOrFail($this->documentId));
    }

    public function envelope(): Envelope
    {
        return $this->inCompany(function () {
            $document = $this->document();
            $brand = $document->brand;
            $brandName = $brand?->name ?? $this->company()->name;

            return new Envelope(
                // The platform's verified sender address with the brand's name; replies go to the brand.
                from: MailSender::from($brand, $this->company()),
                replyTo: MailSender::replyTo($brand, $this->company()),
                subject: __('documents.mail.subject', [
                    'kind' => $this->kind(),
                    'number' => $document->number,
                    'brand' => $brandName,
                ]),
            );
        });
    }

    public function content(): Content
    {
        return $this->inCompany(function () {
            $document = $this->document();

            $review = $this->review($document);
            [$before, $after] = $this->splitMessage($review !== null);

            return new Content(view: 'mail.document', with: [
                'messageBefore' => $before,
                'messageAfter' => $after,
                'review' => $review,
                'url' => PublicDocument::url($document),
                'kind' => $this->kind(),
                'brandName' => $document->brand?->name ?? $this->company()->name,
                'color' => Brand::readableColor($document->brand?->primary_color),
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
                fn () => $this->inCompany(fn () => DocumentPdf::render($this->document())),
                DocumentPdf::filename($this->document()),
            )->withMime('application/pdf'),
        ];
    }

    /**
     * The Google review block of an invoice email: link and optional picture; null when the company has no review
     * link (Company → Google reviews).
     *
     * @return array{url: string, image: string|null}|null
     */
    private function review(Estimate|Invoice $document): ?array
    {
        if (! $document instanceof Invoice) {
            return null;
        }

        $profile = ReviewLink::profileFor($document->brand);
        // Through our address, so a click is seen on the job (no review reminder after it).
        $url = $profile === null ? null : ReviewTracking::link($document->job()->first(), $profile);

        return $url === null ? null : ['url' => $url, 'image' => ReviewImage::url($this->company())];
    }

    /**
     * The message around the review block: it goes right before the "view and pay online" line, or after the whole
     * message when the user removed that line.
     *
     * @return array{string, string}
     */
    private function splitMessage(bool $withReview): array
    {
        $line = __('documents.mail.pay_line');
        $at = $withReview ? mb_strpos($this->messageText, $line) : false;

        if ($at === false) {
            return [$this->messageText, ''];
        }

        return [rtrim(mb_substr($this->messageText, 0, $at)), mb_substr($this->messageText, $at)];
    }

    private function kind(): string
    {
        return __($this->documentClass === Invoice::class ? 'documents.invoice' : 'documents.estimate');
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->companyId);
    }

    /**
     * Queued mail runs without a tenant: everything is rendered inside the document's company.
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
