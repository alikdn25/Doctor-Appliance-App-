<?php

namespace App\Mail;

use App\Models\Brand;
use App\Models\Company;
use App\Models\JobReport;
use App\Support\Mail\MailSender;
use App\Support\Reports\JobReportPdf;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A job report by email, the PDF attached and a link to it online (owner's task of November 25). Only ids are
 * queued; it renders inside the report's company.
 */
class JobReportMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportId, public int $companyId) {}

    public function envelope(): Envelope
    {
        return $this->inCompany(function () {
            $brand = $this->report()->job?->brand;
            $company = Company::query()->find($this->companyId);

            return new Envelope(
                from: MailSender::from($brand, $company),
                replyTo: MailSender::replyTo($brand, $company),
                subject: __('service_reports.email_subject', ['company' => $brand?->name ?? $company?->name]),
            );
        });
    }

    public function content(): Content
    {
        return $this->inCompany(function () {
            $report = $this->report();
            $brand = $report->job?->brand;

            return new Content(view: 'mail.customer-message', with: [
                'body' => __('service_reports.email_body')."\n\n".__('service_reports.view_online').': '.route('reports.public', $report->public_token),
                'brandName' => $brand?->name ?? Company::query()->find($this->companyId)?->name,
                'color' => Brand::readableColor($brand?->primary_color),
                'review' => null,
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
                fn () => $this->inCompany(fn () => JobReportPdf::render($this->report())),
                $this->inCompany(fn () => JobReportPdf::filename($this->report())),
            )->withMime('application/pdf'),
        ];
    }

    private function report(): JobReport
    {
        return JobReport::query()->with('job.brand')->findOrFail($this->reportId);
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
