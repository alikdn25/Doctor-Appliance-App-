<?php

namespace App\Mail;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Message;
use App\Support\Mail\MailSender;
use App\Support\Reviews\ReviewImage;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A customer message sent by email (reminder, on my way, link, review request) when it does not go by SMS.
 * Only ids are queued; it renders inside the message's company.
 */
class CustomerMessageMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * $reviewUrl: a review request by email gets the Google review block (link and the company's picture).
     */
    public function __construct(public int $messageId, public int $companyId, public string $subjectLine, public ?string $reviewUrl = null) {}

    public function envelope(): Envelope
    {
        return $this->inCompany(function () {
            $brand = $this->message()->job?->brand;
            $company = Company::query()->find($this->companyId);

            return new Envelope(
                from: MailSender::from($brand, $company),
                replyTo: MailSender::replyTo($brand, $company),
                subject: $this->subjectLine,
            );
        });
    }

    public function content(): Content
    {
        return $this->inCompany(function () {
            $message = $this->message();
            $brand = $message->job?->brand;

            return new Content(view: 'mail.customer-message', with: [
                'body' => $message->body,
                'brandName' => $brand?->name ?? Company::query()->find($this->companyId)?->name,
                'color' => Brand::readableColor($brand?->primary_color),
                'review' => $this->reviewUrl === null ? null : [
                    'url' => $this->reviewUrl,
                    'image' => ReviewImage::url(Company::query()->findOrFail($this->companyId)),
                ],
            ]);
        });
    }

    private function message(): Message
    {
        return Message::query()->with('job.brand')->findOrFail($this->messageId);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inCompany(callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs(Company::query()->findOrFail($this->companyId), $callback(...));
    }
}
