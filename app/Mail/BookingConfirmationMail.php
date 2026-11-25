<?php

namespace App\Mail;

use App\Models\Brand;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Support\Mail\MailSender;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The booking confirmation email (owner's task of November 25), in the style of the invoice emails: the logo, the
 * text, the appointment in a block and the cancellation policy on its own line. $jobId is null for "Send test to me".
 */
class BookingConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{when: string, address: string|null, service: string|null, phone: string|null, policy: string}  $details
     */
    public function __construct(public ?int $jobId, public int $companyId, public string $subjectLine, public string $body, public array $details) {}

    public function envelope(): Envelope
    {
        return $this->inCompany(function () {
            $brand = $this->brand();
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
            $brand = $this->brand();
            $policy = trim($this->details['policy']);
            // The policy has its own line under the block: the text shows it once.
            $body = $policy !== '' ? trim(preg_replace('/[ \t]{2,}/u', ' ', str_replace($policy, '', $this->body)) ?? $this->body) : $this->body;

            return new Content(view: 'mail.booking-confirmation', with: [
                'body' => $body,
                'details' => $this->details,
                'policy' => $policy,
                'brandName' => $brand?->name ?? Company::query()->find($this->companyId)?->name,
                'logo' => $brand?->logo_url,
                'color' => Brand::readableColor($brand?->primary_color),
            ]);
        });
    }

    private function brand(): ?Brand
    {
        if ($this->jobId !== null) {
            $brand = ServiceJob::withTrashed()->with('brand')->find($this->jobId)?->brand;
            if ($brand !== null) {
                return $brand;
            }
        }

        return Brand::query()->orderBy('id')->first();
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
