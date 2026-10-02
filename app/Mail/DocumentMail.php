<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Billing\DocumentPdf;
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
            $replyTo = $brand?->sender_email ?: $brand?->email;

            return new Envelope(
                // The platform's verified sender address with the brand's name; replies go to the brand.
                from: new Address((string) config('mail.from.address'), $brand?->sender_name ?: $brandName),
                replyTo: $replyTo ? [new Address($replyTo, $brand?->sender_name ?: $brandName)] : [],
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

            return new Content(view: 'mail.document', with: [
                'messageText' => $this->messageText,
                'url' => PublicDocument::url($document),
                'kind' => $this->kind(),
                'brandName' => $document->brand?->name ?? $this->company()->name,
                'color' => $document->brand?->primary_color ?: '#0f172a',
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
