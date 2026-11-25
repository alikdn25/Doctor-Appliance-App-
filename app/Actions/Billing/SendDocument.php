<?php

namespace App\Actions\Billing;

use App\Mail\DocumentMail;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Billing\Money;
use App\Support\Billing\PublicDocument;
use Illuminate\Support\Facades\Mail;

/**
 * Emails an estimate or invoice to the customer (PDF + link to the online page) and records when and to whom.
 * Must run in a tenant context.
 */
class SendDocument
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SyncJobBillingStatus $syncJob,
    ) {}

    public function handle(Estimate|Invoice $document, string $email, string $message, User $user): void
    {
        PublicDocument::token($document);

        Mail::to($email)->queue(new DocumentMail($document, $message));

        $document->forceFill(['sent_at' => now(), 'edited_after_send_at' => null, 'sent_to' => $email,
            ...($document instanceof Estimate ? ['followup_processed_at' => null] : []),
        ])->saveQuietly();

        $this->audit->record($document instanceof Invoice ? 'invoice.sent' : 'estimate.sent', $document, [
            'number' => $document->number, 'to' => $email,
        ]);

        // A sent invoice with money owed puts the job in "Awaiting payment"; a sent estimate changes nothing.
        $job = $document instanceof Invoice ? $document->job()->first() : null;
        if ($job !== null) {
            $this->syncJob->handle($job, $user);
        }
    }

    /**
     * The message the send dialog starts with (editable).
     */
    public static function defaultMessage(Estimate|Invoice $document): string
    {
        $document->loadMissing(['customer', 'brand']);
        $company = currentCompany();
        $money = fn (int $minor) => Money::format($minor, $document->currency, $company->locale);

        $key = match (true) {
            ! $document instanceof Invoice => 'estimate',
            $document->balance <= 0 => 'invoice_paid',
            default => 'invoice',
        };

        return __("documents.default_message.{$key}", [
            'name' => $document->customer?->first_name ?: $document->customer?->display_name,
            'brand' => $document->brand?->name ?? $company->name,
            'number' => $document->number,
            'total' => $money($document->total),
            'pay_line' => __('documents.mail.pay_line'),
        ]);
    }
}
