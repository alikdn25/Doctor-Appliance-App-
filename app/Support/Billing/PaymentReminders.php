<?php

namespace App\Support\Billing;

use App\Enums\ApplianceType;
use App\Enums\InvoiceStatus;
use App\Enums\MessageKind;
use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Soft payment reminders (owner's task of November 19): a friendly text about an open invoice, with no amount and no
 * link, sent only after the person's Confirm (one or several invoices). The customer's answer gets a reply draft with
 * the amount and the invoice link (SmsTriage); one tap sends it. Nothing is ever texted again automatically: an
 * unanswered reminder gives the "customer is quiet" reminder. Must run in a tenant context.
 */
class PaymentReminders
{
    public const NOT_REMINDED = 'not_reminded';

    public const REMINDED = 'reminded';

    public const ANSWERED = 'answered';

    public function __construct(private readonly Messenger $messenger) {}

    /**
     * The company's template, or the default one.
     */
    public static function template(Company $company): string
    {
        $own = trim((string) $company->payment_reminder_template);

        return $own !== '' ? $own : __('debtors.reminder.default');
    }

    /**
     * The text for one invoice: {first_name}, {my_name}, {company}, {appliance}, {month}. No amount, no link.
     */
    public static function text(Invoice $invoice, User $user): string
    {
        $company = currentCompany();
        $invoice->loadMissing(['customer', 'job.appliances', 'brand']);
        $issued = CarbonImmutable::instance($invoice->issued_on);
        $month = $issued->year === CarbonImmutable::now($company->timezone)->year ? $issued->isoFormat('MMMM') : $issued->isoFormat('MMMM YYYY');

        return strtr(self::template($company), [
            '{first_name}' => (string) ($invoice->customer?->first_name ?: $invoice->customer?->display_name),
            '{my_name}' => Str::before(trim($user->name), ' '),
            '{company}' => $invoice->brand?->name ?? $company->name,
            '{appliance}' => self::appliance($invoice),
            '{month}' => $month,
        ]);
    }

    /**
     * "dryer": the job's appliance, else an appliance named in the work, else "appliance".
     */
    public static function appliance(Invoice $invoice): string
    {
        $appliance = $invoice->job?->appliances->first();
        if ($appliance !== null && $appliance->type !== ApplianceType::Other) {
            return mb_strtolower($appliance->type->label());
        }

        $work = mb_strtolower((string) $invoice->job?->description.' '.$invoice->items()->value('description'));
        foreach (ApplianceType::cases() as $type) {
            $label = mb_strtolower($type->label());
            if ($type !== ApplianceType::Other && $label !== '' && str_contains($work, $label)) {
                return $label;
            }
        }

        return __('debtors.reminder.appliance');
    }

    /**
     * Sends the reminders the person confirmed. Paid or void invoices are skipped.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array{sent: int, blocked: list<string>}
     */
    public function send(Collection $invoices, User $user): array
    {
        $sent = 0;
        $blocked = [];

        foreach ($invoices as $invoice) {
            if (! in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)) {
                continue;
            }

            $message = $this->messenger->sms(MessageKind::PaymentReminder, $invoice->customer, $invoice->job, self::text($invoice, $user), $user);
            if ($message->status === 'blocked') {
                $blocked[] = $invoice->number.': '.$message->status_reason;

                continue;
            }

            $invoice->forceFill(['reminder_sent_at' => now(), 'reminder_message_id' => $message->id, 'reminder_answered_at' => null])->save();
            $sent++;
        }

        if ($sent === 0 && $blocked === []) {
            throw ValidationException::withMessages(['invoice_ids' => __('debtors.reminder.nothing')]);
        }

        return ['sent' => $sent, 'blocked' => $blocked];
    }

    public static function status(Invoice $invoice): string
    {
        return match (true) {
            $invoice->reminder_answered_at !== null => self::ANSWERED,
            $invoice->reminder_sent_at !== null => self::REMINDED,
            default => self::NOT_REMINDED,
        };
    }

    /**
     * An incoming text that answers a payment reminder (the latest text we sent to this number in the last 60 days
     * was one, and its invoice is still open): the invoice is marked answered and returned.
     */
    public static function answered(Message $incoming): ?Invoice
    {
        $ours = Message::query()->where('direction', Message::OUTBOUND)->where('to', $incoming->from)
            ->where('id', '<', $incoming->id)->where('created_at', '>=', now()->subDays(60))
            ->whereNotIn('kind', [MessageKind::AutoReply->value])->latest('id')->first();

        if ($ours === null || $ours->kind !== MessageKind::PaymentReminder) {
            return null;
        }

        $invoice = Invoice::query()->where('reminder_message_id', $ours->id)
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value])->first();
        $invoice?->forceFill(['reminder_answered_at' => $invoice->reminder_answered_at ?? now()])->save();

        return $invoice;
    }

    /**
     * The amount and the link that must be in the reply draft.
     *
     * @return array{amount: string, link: string, number: string}
     */
    public static function facts(Invoice $invoice): array
    {
        return [
            'amount' => Money::format($invoice->balance, $invoice->currency, currentCompany()->locale),
            'link' => PublicDocument::url($invoice),
            'number' => $invoice->number,
        ];
    }
}
