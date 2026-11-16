<?php

namespace App\Services\AI\Assistant;

use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Billing\SendDocument;
use App\Actions\Customers\SaveCustomer;
use App\Actions\Customers\SaveProperty;
use App\Actions\Jobs\ChangeJobStatus;
use App\Actions\Jobs\SaveJob;
use App\Actions\Jobs\SaveVisit;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Enums\MessageKind;
use App\Enums\SmsMode;
use App\Enums\VisitStatus;
use App\Messaging\MessagingPresenter;
use App\Messaging\Messenger;
use App\Models\AssistantAction;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAttachment;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\Money;
use App\Support\PrivateMedia;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves an invoice the assistant proposed (create_invoice), only after the person pressed Confirm & send or Edit:
 * the customer is found by phone or email (else created), the invoice goes on the customer's open job (else a job
 * for today, Completed, is made for it), the photos it was read from become its attachments. Confirm & send then
 * texts the invoice link (company SMS) and emails it when mail is set up; Edit sends nothing and opens the editor.
 */
class AssistantInvoice
{
    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveProperty $saveProperty,
        private readonly SaveJob $saveJob,
        private readonly SaveVisit $saveVisit,
        private readonly ChangeJobStatus $changeStatus,
        private readonly SaveBillingDocument $saveDocument,
        private readonly Messenger $messenger,
        private readonly SendDocument $sendDocument,
    ) {}

    /**
     * @return array{message: string, invoice: Invoice, redirect?: string}
     */
    public function confirm(AssistantAction $action, User $user, bool $send): array
    {
        $draft = $action->params['draft'];

        $invoice = DB::transaction(function () use ($action, $draft, $user) {
            $customer = $this->customer($draft['customer']);
            $job = InvoiceDraft::openJob($customer) ?? $this->newJob($customer, $draft, $user);

            $invoice = $this->saveDocument->createInvoice($job, [
                'issued_on' => CarbonImmutable::now(currentCompany()->timezone)->toDateString(),
                'due_on' => null,
                'notes' => $draft['notes'],
                'tax_rate_ids' => $draft['tax_rate_ids'],
                'items' => array_map(fn (array $line) => [
                    'description' => $line['description'],
                    'quantity' => '1',
                    'unit_price' => $line['unit_price'],
                    'taxable' => true,
                    'kind' => LineKind::Service->value,
                ], $draft['lines']),
            ], $user);

            $this->attach($invoice, (array) ($action->params['photos'] ?? []), $user);

            return $invoice;
        });

        $money = Money::format($invoice->total, $invoice->currency, currentCompany()->locale);

        if (! $send) {
            return [
                'message' => __('assistant.invoice.drafted', ['number' => $invoice->number, 'total' => $money]),
                'invoice' => $invoice,
                'redirect' => route('invoices.edit', $invoice),
            ];
        }

        $sent = array_filter([$this->sms($invoice, $user), $this->email($invoice, $user)]);

        return [
            'message' => __('assistant.invoice.created', ['number' => $invoice->number, 'total' => $money]).' '
                .($sent === [] ? __('assistant.invoice.not_sent') : implode(' ', $sent)),
            'invoice' => $invoice,
        ];
    }

    /**
     * The customer with this phone or email, else a new one (with the address when one was read).
     *
     * @param  array<string, mixed>  $data
     */
    private function customer(array $data): Customer
    {
        [$customer] = InvoiceDraft::findCustomer($data['phone'], (string) ($data['email'] ?? ''));

        if ($customer !== null) {
            // A customer without an address gets the one on the invoice.
            if ($data['address'] !== null && ! $customer->properties()->whereNotNull('line1')->where('line1', '!=', '')->exists()) {
                $this->saveProperty->handle($customer, null, $this->property($data['address']));
            }

            return $customer;
        }

        return $this->saveCustomer->handle(
            null,
            ['type' => 'residential', 'first_name' => $data['first_name'], 'last_name' => $data['last_name']],
            [['number' => $data['phone'], 'label' => 'mobile', 'is_primary' => true]],
            $data['email'] ? [['email' => $data['email'], 'label' => 'personal', 'is_primary' => true]] : [],
            $data['address'] === null ? null : $this->property($data['address']),
        );
    }

    /**
     * @param  array<string, string>  $address
     * @return array<string, string|null>
     */
    private function property(array $address): array
    {
        return [
            'line1' => $address['line1'],
            'unit' => ($address['unit'] ?? '') ?: null,
            'city' => ($address['city'] ?? '') ?: null,
            'region' => ($address['region'] ?? '') ?: null,
            'postal_code' => ($address['postal_code'] ?? '') ?: null,
            'country' => currentCompany()->country,
        ];
    }

    /**
     * An invoice always belongs to a job: without an open one, a job for today is made, already Completed, with a
     * finished visit of the person who confirmed.
     *
     * @param  array<string, mixed>  $draft
     */
    private function newJob(Customer $customer, array $draft, User $user): ServiceJob
    {
        $property = $customer->primaryProperty()->first() ?? $customer->properties()->orderBy('id')->first();
        if ($property === null) {
            throw ValidationException::withMessages(['address' => __('assistant.invoice.no_address')]);
        }

        $limited = $user->limitedBrandIds();
        $brand = Brand::query()->where('is_active', true)
            ->when($limited !== [], fn ($q) => $q->whereIn('id', $limited))
            ->orderBy('id')->first();
        if ($brand === null) {
            throw ValidationException::withMessages(['brand' => __('assistant.invoice.no_brand')]);
        }

        $job = $this->saveJob->create($customer, [
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'job_type' => currentCompany()->vertical->jobTypes()[0]->value,
            'description' => mb_substr(implode(', ', array_column($draft['lines'], 'description')), 0, 1000),
        ], [], [], null, null, $user);

        $start = CarbonImmutable::now(currentCompany()->timezone)->startOfHour();
        $visit = $this->saveVisit->handle($job, null, ['scheduled_start' => $start->utc(), 'scheduled_end' => $start->addHour()->utc()], [$user->id], $user);
        $visit->forceFill(['status' => VisitStatus::Completed, 'started_at' => $start->utc(), 'finished_at' => now()])->save();

        $job->refresh();
        $job->outcome = JobOutcome::Repaired;
        $this->changeStatus->handle($job, JobStatus::Completed, $user, $visit);

        return $job;
    }

    /**
     * The photos the invoice was read from, copied into its attachments.
     *
     * @param  list<array{path: string, media_type: string}>  $photos
     */
    private function attach(Invoice $invoice, array $photos, User $user): void
    {
        $disk = PrivateMedia::disk();

        foreach (array_slice($photos, 0, AssistantPhotos::MAX) as $photo) {
            if (! $disk->exists($photo['path'])) {
                continue;
            }

            $path = 'invoice-attachments/'.$invoice->company_id.'/'.$invoice->id.'/'.Str::uuid().'.'.AssistantPhotos::extension($photo['media_type']);
            $disk->copy($photo['path'], $path);

            InvoiceAttachment::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'path' => $path,
                'mime_type' => $photo['media_type'],
                'size' => $disk->size($path),
            ]);
        }
    }

    /** The invoice link by text from the company number (Telnyx or the company's SMS provider). */
    private function sms(Invoice $invoice, User $user): ?string
    {
        if (currentCompany()->sms_mode !== SmsMode::Automatic) {
            return __('assistant.invoice.sms_off');
        }

        $invoice->loadMissing(['customer', 'job']);
        $message = $this->messenger->sms(MessageKind::InvoiceLink, $invoice->customer, $invoice->job, MessagingPresenter::documentText($invoice), $user);

        if ($message->status === 'blocked') {
            return __('messages.sms_blocked', ['reason' => $message->status_reason]);
        }

        $invoice->forceFill(['sent_at' => now(), 'sent_to' => $message->to])->saveQuietly();

        return __('assistant.invoice.sms_sent', ['phone' => $message->to]);
    }

    /** The invoice by email, when the customer has an email and mail is set up. */
    private function email(Invoice $invoice, User $user): ?string
    {
        $email = $invoice->customer?->primaryEmail?->email ?? $invoice->customer?->emails()->value('email');

        if ($email === null || ! self::mailConfigured()) {
            return null;
        }

        try {
            $this->sendDocument->handle($invoice, $email, SendDocument::defaultMessage($invoice), $user);
        } catch (Throwable $e) {
            report($e);

            return __('assistant.invoice.email_failed');
        }

        return __('assistant.invoice.email_sent', ['email' => $email]);
    }

    /** Whether real email goes out (not the log or test mailer). */
    public static function mailConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        return ! in_array($mailer, ['log', 'array'], true) && ! in_array($transport, ['log', 'array'], true);
    }
}
