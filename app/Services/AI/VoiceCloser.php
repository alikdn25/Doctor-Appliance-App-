<?php

namespace App\Services\AI;

use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Jobs\ChangeJobStatus;
use App\Actions\Jobs\CloseJob;
use App\Actions\Jobs\VisitWorkflow;
use App\Enums\ApplianceCategory;
use App\Enums\InvoiceStatus;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Enums\MessageKind;
use App\Enums\PaymentMethod;
use App\Enums\VisitStatus;
use App\Messaging\MessagingPresenter;
use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JobPart;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\ServiceJob;
use App\Models\User;
use App\Services\AI\Assistant\AssistantActions;
use App\Support\Accounting\JobExpenses;
use App\Support\Audit\ActionSource;
use App\Support\Billing\ChargeLines;
use App\Support\Billing\Money;
use App\Support\Billing\PublicDocument;
use App\Support\Locale\Currencies;
use App\Support\Statistics\ApplianceGuess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Close by voice" on the job page (owner's task of November 18): what the technician said goes to AI_MODEL_ASSISTANT,
 * which fills the note about the work, the invoice lines, whether the amounts include tax, how it was paid and the
 * new status (Completed, or Need to order parts with the part). Since November 24 it also splits off what the technician
 * paid themselves (My expenses) and marks unsure lines "check". Taxes and totals are calculated by code. The preview is
 * edited; only Confirm & send / Save only saves (Confirm & send also texts the invoice or the receipt).
 */
class VoiceCloser
{
    public const PAYMENTS = ['card', 'cash', 'e_transfer', 'unpaid'];

    public const STATUSES = ['completed', 'need_to_order_parts'];

    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array<string, mixed> The AI's reading (fields as the preview edits them).
     *
     * @throws AiUnavailable
     */
    public function read(ServiceJob $job, string $text, User $user): array
    {
        $company = currentCompany();
        $job->loadMissing(['customer', 'appliances']);
        $context = sprintf("Job #%d for %s. Appliance: %s. Problem: %s.\nThe technician says: %s",
            $job->number, $job->customer?->display_name, $job->appliances->map->label()->implode(', ') ?: '—',
            $job->description ?: '—', trim($text));

        $raw = $this->ai->send(
            AiFeature::VoiceClose,
            $company,
            $user,
            'You fill in how a repair job ended from what the technician said (Russian, English or mixed). Write in English. '
            .'note: what was found and done, short. lines: what the CUSTOMER pays — one line per piece of work, part or material, with its '
            .'amount as said; never invent an amount (no amount said = no line). expenses: what the technician or company PAID ITSELF for '
            .'this job (a part bought at a store, parking, gas) with the amount as said; these never go on the invoice. When it is unclear '
            .'whether an amount is charged to the customer or was spent, put it where it most likely belongs and set check = true. '
            .'payment: card, cash, e_transfer, or unpaid when not said or not paid. status: completed, or '
            .'need_to_order_parts when a part must be ordered (part_name = the part, else ""). appliance_type: the appliance '
            .'worked on (one of the listed values; unknown when not said). brand: its manufacturer in the usual English '
            .'spelling ("элджи" -> "LG"), else "". Currency: '.$company->currency.'.',
            [['role' => 'user', 'content' => $context]],
            schema: self::schema(),
            maxTokens: 1500,
        )->json();

        $lines = [];
        $defaultTaxes = ChargeLines::defaultIds();
        foreach ((array) ($raw['lines'] ?? []) as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount > 0) {
                $lines[] = [
                    'description' => mb_substr(trim((string) ($line['description'] ?? '')) ?: __('assistant.invoice.default_line'), 0, 500),
                    'amount' => round($amount, 2),
                    'kind' => ($line['kind'] ?? '') === 'part' ? 'part' : 'service',
                    // The first company tax is ticked; the technician changes the ticks in the preview.
                    'tax_rate_ids' => $defaultTaxes,
                    'check' => (bool) ($line['check'] ?? false),
                ];
            }
        }
        $expenses = [];
        foreach ((array) ($raw['expenses'] ?? []) as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount > 0) {
                $expenses[] = [
                    'description' => mb_substr(trim((string) ($line['description'] ?? '')), 0, 255),
                    'amount' => round($amount, 2),
                    'check' => (bool) ($line['check'] ?? false),
                ];
            }
        }

        return [
            'note' => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 5000),
            'lines' => $lines,
            'expenses' => $expenses,
            // "Price includes tax" starts off on every preview; the technician switches it on there.
            'amounts_include_tax' => false,
            'payment' => in_array($raw['payment'] ?? null, self::PAYMENTS, true) ? $raw['payment'] : 'unpaid',
            'status' => in_array($raw['status'] ?? null, self::STATUSES, true) ? $raw['status'] : 'completed',
            'part_name' => mb_substr(trim((string) ($raw['part_name'] ?? '')), 0, 255),
            // For Statistics; empty = keep what the job has.
            'appliance_type' => ApplianceCategory::tryFrom((string) ($raw['appliance_type'] ?? ''))?->value,
            'brand' => ApplianceGuess::normalizeBrand($raw['brand'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'note' => $text,
                'lines' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['description' => $text, 'amount' => ['type' => 'number'], 'kind' => ['type' => 'string', 'enum' => ['service', 'part']], 'check' => ['type' => 'boolean']],
                    'required' => ['description', 'amount', 'kind', 'check'],
                    'additionalProperties' => false,
                ]],
                'expenses' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['description' => $text, 'amount' => ['type' => 'number'], 'check' => ['type' => 'boolean']],
                    'required' => ['description', 'amount', 'check'],
                    'additionalProperties' => false,
                ]],
                'payment' => ['type' => 'string', 'enum' => self::PAYMENTS],
                'status' => ['type' => 'string', 'enum' => self::STATUSES],
                'part_name' => $text,
                'appliance_type' => ['type' => 'string', 'enum' => [...array_map(fn (ApplianceCategory $c) => $c->value, ApplianceCategory::cases()), 'unknown']],
                'brand' => $text,
            ],
            'required' => ['note', 'lines', 'expenses', 'payment', 'status', 'part_name', 'appliance_type', 'brand'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The preview's money: each line with its own taxes (ChargeLines), in the invoice's price mode (code).
     *
     * @param  array<string, mixed>  $data
     * @return array{lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>, subtotal: int, tax_total: int, total: int, currency: string, tax_rate_ids: list<int>}
     */
    public static function totals(array $data, Company $company): array
    {
        return ChargeLines::totals((array) $data['lines'], (bool) $data['amounts_include_tax'], $company);
    }

    /**
     * Confirm & send / Save only: note, invoice, My expenses, payment and status, as the person who pressed it (change
     * log "AI assistant"). With $send the customer gets a text: the invoice with its payment link while money is owed,
     * else the receipt.
     *
     * @param  array<string, mixed>  $data  note, lines, expenses, amounts_include_tax, payment, status, part_name
     * @return array{type: string, message: string}
     */
    public function confirm(ServiceJob $job, array $data, User $user, bool $send = false): array
    {
        $company = currentCompany();
        $money = self::totals($data, $company);

        if ($data['payment'] !== 'unpaid' && $money['total'] <= 0) {
            throw ValidationException::withMessages(['payment' => __('automation.voice_close.no_amount')]);
        }

        $invoice = app(ActionSource::class)->as(AssistantActions::SOURCE, fn () => DB::transaction(function () use ($job, $data, $user, $money, $company) {
            $job = ServiceJob::query()->lockForUpdate()->findOrFail($job->id);

            if (trim((string) $data['note']) !== '') {
                $job->tech_notes = trim(implode("\n", array_filter([$job->tech_notes, trim($data['note'])])));
            }
            if (filled($data['appliance_type'] ?? null)) {
                $job->appliance_category = ApplianceCategory::from($data['appliance_type']);
            }
            if (filled($data['brand'] ?? null)) {
                $job->appliance_brand = ApplianceGuess::normalizeBrand($data['brand']);
            }
            $job->save();
            // The part named goes to the Parts page (Order); the job's status follows when the visit is finished.
            if ($data['status'] === 'need_to_order_parts' && trim((string) $data['part_name']) !== '') {
                $part = new JobPart(['service_job_id' => $job->id, 'name' => mb_substr(trim($data['part_name']), 0, 255)]);
                $part->created_by = $user->id;
                $part->save();
            }

            // My expenses: what was paid for the job; business expenses linked to it, never on the invoice.
            app(JobExpenses::class)->add($job, $user, array_map(fn (array $line) => [
                'description' => $line['description'] ?? null,
                'amount' => Currencies::toMinor((string) $line['amount'], $company->currency),
            ], array_values((array) ($data['expenses'] ?? []))));

            $invoice = null;
            if ($money['lines'] !== []) {
                $invoice = app(SaveBillingDocument::class)->createInvoice($job, [
                    'issued_on' => CarbonImmutable::now($company->timezone)->toDateString(),
                    'due_on' => null,
                    'notes' => null,
                    'tax_rate_ids' => $money['tax_rate_ids'],
                    'items' => array_map(fn (array $line) => [
                        'description' => $line['description'],
                        'quantity' => '1',
                        'unit_price' => $line['unit_price'],
                        'taxable' => $line['tax_rate_ids'] !== [],
                        'tax_rate_ids' => $line['tax_rate_ids'],
                        'kind' => ($line['kind'] ?? 'service') === 'part' ? LineKind::Part->value : LineKind::Service->value,
                    ], $money['lines']),
                ], $user);
            }

            if ($invoice !== null && $data['payment'] !== 'unpaid' && $invoice->balance > 0) {
                app(RecordPayment::class)->manual($invoice->refresh(), $invoice->balance, self::method($data['payment']), null, null, now(), $user);
            }

            $this->finish($job->refresh(), $data['status'] === 'need_to_order_parts' ? JobStatus::NeedToOrderParts : JobStatus::Completed, $user);

            return $invoice;
        }));

        $saved = __('automation.voice_close.saved', ['number' => $job->number]);
        if (! $send) {
            return ['type' => 'success', 'message' => $saved];
        }

        // The invoice just made, else the job's newest one that is not void.
        $invoice = $invoice?->refresh() ?? Invoice::query()->where('service_job_id', $job->id)
            ->where('status', '!=', InvoiceStatus::Void->value)->latest('id')->first();
        if ($invoice === null || $invoice->customer_id === null) {
            return ['type' => 'info', 'message' => $saved.' '.__('automation.voice_close.nothing_to_send')];
        }

        $message = $this->text($invoice, $user);

        return $message->status === 'blocked'
            ? ['type' => 'error', 'message' => $saved.' '.__('messages.sms_blocked', ['reason' => $message->status_reason])]
            : ['type' => 'success', 'message' => $saved.' '.__($invoice->balance > 0 ? 'automation.voice_close.invoice_sent' : 'automation.voice_close.receipt_sent', ['number' => $invoice->number])];
    }

    /**
     * The text to the customer: the invoice with its payment link while money is owed, else the receipt (the link
     * opens the paid invoice).
     */
    private function text(Invoice $invoice, User $user): Message
    {
        $invoice->loadMissing(['customer', 'job.brand', 'brand']);
        $company = currentCompany();
        $messenger = app(Messenger::class);

        if ($invoice->balance > 0) {
            $message = $messenger->sms(MessageKind::InvoiceLink, $invoice->customer, $invoice->job, MessagingPresenter::documentText($invoice), $user);
            if ($message->status !== 'blocked') {
                $invoice->forceFill(['sent_at' => now(), 'sent_to' => $message->to])->saveQuietly();
            }

            return $message;
        }

        $body = __('automation.voice_close.receipt_text', [
            'number' => $invoice->number,
            'amount' => Money::format($invoice->amount_paid, $invoice->currency, $company->locale),
            'link' => PublicDocument::url($invoice),
            'company' => $invoice->job?->brand?->name ?? $invoice->brand?->name ?? $company->name,
        ]);

        return $messenger->sms(MessageKind::Receipt, $invoice->customer, $invoice->job, $body, $user);
    }

    private static function method(string $payment): PaymentMethod
    {
        return match ($payment) {
            'cash' => PaymentMethod::Cash,
            'e_transfer' => PaymentMethod::BankTransfer,
            default => PaymentMethod::CardTerminal,
        };
    }

    /**
     * The visit under way (or the one booked for today) is finished; Completed also closes the job as repaired.
     */
    private function finish(ServiceJob $job, JobStatus $status, User $user): void
    {
        $today = CarbonImmutable::now(currentCompany()->timezone)->endOfDay()->utc();
        $visit = $job->visits()->whereIn('status', [VisitStatus::InProgress->value, VisitStatus::OnTheWay->value])->first()
            ?? $job->visits()->where('status', VisitStatus::Scheduled->value)->where('scheduled_start', '<=', $today)->orderByDesc('scheduled_start')->first();
        $workflow = app(VisitWorkflow::class);

        if ($visit instanceof JobVisit && $job->status->allowsVisitWork()) {
            if ($visit->status !== VisitStatus::InProgress) {
                $workflow->start($visit, $user);
            }
            $workflow->finish($visit->refresh(), $user, $status);
            $job->refresh();
        } elseif ($status === JobStatus::NeedToOrderParts && ! $job->status->isLocked()) {
            app(ChangeJobStatus::class)->handle($job, $status, $user);
        }

        if ($status === JobStatus::Completed && $job->outcome === null) {
            app(CloseJob::class)->close($job, JobOutcome::Repaired, null, null, $user, $visit);
        }
    }
}
