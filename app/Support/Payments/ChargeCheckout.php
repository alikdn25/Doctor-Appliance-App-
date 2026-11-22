<?php

namespace App\Support\Payments;

use App\Actions\Billing\CashLedger;
use App\Actions\Billing\RecordPayment;
use App\Actions\Billing\SaveBillingDocument;
use App\Actions\Customers\SaveProperty;
use App\Actions\Jobs\ChangeJobStatus;
use App\Actions\Jobs\SaveJob;
use App\Actions\Jobs\SaveVisit;
use App\Enums\JobOutcome;
use App\Enums\JobStatus;
use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Charge;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Billing\ChargeLines;
use App\Support\Locale\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Take payment screen (owner's task of November 21): Charge reader, Cash or E-transfer for an invoice's balance
 * (or part of it), or for a charge without a booking. A charge without a booking makes, once paid: the customer
 * (PaidCustomer: by phone, new, or Walk-in), a job Completed now with the person as its technician, and the invoice,
 * paid. Taxes always come from code (the company's default rates; the screen only ticks which of them each line has). Must run in a tenant
 * context.
 */
class ChargeCheckout
{
    /** The ways to pay on the screen. */
    public const METHODS = [PaymentMethod::CardReader, PaymentMethod::Cash, PaymentMethod::BankTransfer];

    public function __construct(
        private readonly RecordPayment $recordPayment,
        private readonly SaveBillingDocument $saveDocument,
        private readonly SaveProperty $saveProperty,
        private readonly SaveJob $saveJob,
        private readonly SaveVisit $saveVisit,
        private readonly ChangeJobStatus $changeStatus,
        private readonly CashLedger $cash,
        private readonly PaidCustomer $customers,
    ) {}

    /**
     * Totals of the lines typed (amounts with or without tax; each line with its own taxes), in the company's
     * currency and tax rates. Empty lines (no description, no amount) are left out; a line with an amount and no
     * description is "Service".
     *
     * @param  list<array{description?: string|null, amount?: string|float|int|null, tax_rate_ids?: list<int>|null}>  $lines
     * @return array<string, mixed>
     */
    public static function totals(array $lines, bool $amountsIncludeTax): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            if ((float) ($line['amount'] ?? 0) <= 0) {
                continue;
            }
            $kept[] = [
                'description' => $description !== '' ? mb_substr($description, 0, 500) : __('charges.default_line'),
                'amount' => $line['amount'],
                'kind' => 'service',
                ...(array_key_exists('tax_rate_ids', $line) ? ['tax_rate_ids' => (array) $line['tax_rate_ids']] : []),
            ];
        }

        return ChargeLines::totals($kept, $amountsIncludeTax, currentCompany());
    }

    /**
     * Starts a charge. Cash and E-transfer are paid at once; Charge reader waits for the Square payment.
     *
     * @param  array<string, mixed>|null  $draft  Without a booking: lines, amounts_include_tax, customer.
     */
    public function start(User $user, ?Invoice $invoice, ?array $draft, ?int $amount, PaymentMethod $method): Charge
    {
        $company = currentCompany();

        if (! in_array($method, self::METHODS, true) || ($method === PaymentMethod::Cash && $company->accepts_cash === false)) {
            throw ValidationException::withMessages(['method' => __('payments.errors.invalid_method')]);
        }

        if ($invoice !== null) {
            $invoice->refresh();
            if ($invoice->isVoid() || $invoice->balance <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.links.nothing_due')]);
            }
            $amount ??= $invoice->balance;
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.errors.amount_required')]);
            }
            if ($amount > $invoice->balance) {
                throw ValidationException::withMessages(['amount' => __('payments.errors.more_than_balance')]);
            }
            $currency = $invoice->currency;
        } else {
            $totals = self::totals((array) ($draft['lines'] ?? []), (bool) ($draft['amounts_include_tax'] ?? false));
            if ($totals['total'] <= 0) {
                throw ValidationException::withMessages(['amount' => __('payments.errors.amount_required')]);
            }
            $amount = $totals['total'];
            $currency = $company->currency;
        }

        $charge = Charge::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice?->id,
            'amount' => $amount,
            'currency' => $currency,
            'method' => $method,
            'draft' => $invoice === null ? $draft : null,
        ]);

        return $method === PaymentMethod::CardReader ? $charge : $this->complete($charge);
    }

    /**
     * Records the payment of a charge, once (the phone and the webhook may both report the same Square payment).
     * $square is the Square payment (COMPLETED) for a card reader charge.
     *
     * @param  array<string, mixed>|null  $square
     */
    public function complete(Charge $charge, ?array $square = null): Charge
    {
        return DB::transaction(function () use ($charge, $square) {
            $charge = Charge::query()->lockForUpdate()->findOrFail($charge->id);

            if ($charge->isPaid()) {
                return $charge;
            }

            $user = $charge->user ?? throw ValidationException::withMessages(['charge' => __('charges.errors.no_user')]);
            $invoice = $charge->invoice ?? $this->invoiceFromDraft($charge, $user);

            if ($charge->method === PaymentMethod::CardReader) {
                $card = $square['card_details']['card'] ?? null;
                $reference = is_array($card) && isset($card['card_brand'], $card['last_4'])
                    ? Str::of($card['card_brand'])->replace('_', ' ')->title().' •••• '.$card['last_4']
                    : ($square['receipt_number'] ?? null);

                $payment = $this->recordPayment->fromProvider(
                    $invoice,
                    'square',
                    (string) $square['id'],
                    (int) ($square['amount_money']['amount'] ?? $charge->amount),
                    CarbonImmutable::parse($square['updated_at'] ?? $square['created_at'] ?? 'now'),
                    $reference,
                    $square['amount_money']['currency'] ?? null,
                    (int) ($square['tip_money']['amount'] ?? 0),
                    PaymentMethod::CardReader,
                    $user,
                );
                $fee = collect($square['processing_fee'] ?? [])->sum(fn ($f) => (int) ($f['amount_money']['amount'] ?? 0));
                if ($fee > 0) {
                    $payment->forceFill(['processing_fee' => $fee])->save();
                }
            } else {
                $payment = $this->recordPayment->manual($invoice, $charge->amount, $charge->method, null, null, now(), $user);
                if ($payment->method === PaymentMethod::Cash) {
                    $this->cash->collected($payment, $user);
                }
            }

            $charge->forceFill([
                'status' => Charge::PAID,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'provider_payment_id' => $payment->provider_payment_id,
                'error' => null,
                'paid_at' => now(),
            ])->save();

            return $charge;
        });
    }

    /**
     * The reader or Square said no (declined, cancelled): nothing is recorded, the invoice stays unpaid.
     */
    public function fail(Charge $charge, string $error): void
    {
        if ($charge->isPaid()) {
            return;
        }

        $charge->forceFill(['status' => Charge::FAILED, 'error' => mb_substr($error, 0, 500)])->save();
    }

    /**
     * The customer, a job Completed now (technician: the person charging) and the invoice of a charge without a
     * booking.
     */
    private function invoiceFromDraft(Charge $charge, User $user): Invoice
    {
        $draft = (array) $charge->draft;
        $totals = self::totals((array) ($draft['lines'] ?? []), (bool) ($draft['amounts_include_tax'] ?? false));

        if ($totals['total'] !== $charge->amount) {
            // The tax rates changed between Charge and the payment: the invoice is made for what was charged.
            $first = $totals['lines'][0] ?? [];
            $totals = self::totals([[
                'description' => $first['description'] ?? '',
                'amount' => $charge->amount / Currencies::factor($charge->currency),
                ...(isset($first['tax_rate_ids']) ? ['tax_rate_ids' => $first['tax_rate_ids']] : []),
            ]], true);
        }

        $customer = $this->customers->resolve(is_array($draft['customer'] ?? null) ? $draft['customer'] : null);
        $job = $this->completedJob($customer, $totals, $user);

        return $this->saveDocument->createInvoice($job, [
            'issued_on' => CarbonImmutable::now(currentCompany()->timezone)->toDateString(),
            'due_on' => null,
            'notes' => null,
            'tax_rate_ids' => $totals['tax_rate_ids'],
            'items' => array_map(fn (array $line) => [
                'description' => $line['description'],
                'quantity' => '1',
                'unit_price' => $line['unit_price'],
                'taxable' => $line['tax_rate_ids'] !== [],
                'tax_rate_ids' => $line['tax_rate_ids'],
                'kind' => LineKind::Service->value,
            ], $totals['lines']),
        ], $user);
    }

    /**
     * A job for now, already Completed (repaired), with a finished visit of the person charging ("Me").
     *
     * @param  array<string, mixed>  $totals
     */
    private function completedJob(Customer $customer, array $totals, User $user): ServiceJob
    {
        $property = $customer->primaryProperty()->first()
            ?? $customer->properties()->orderBy('id')->first()
            ?? $this->saveProperty->empty($customer);

        $limited = $user->limitedBrandIds();
        $brand = Brand::query()->where('is_active', true)
            ->when($limited !== [], fn ($q) => $q->whereIn('id', $limited))
            ->orderBy('id')->first()
            ?? throw ValidationException::withMessages(['brand' => __('assistant.invoice.no_brand')]);

        $job = $this->saveJob->create($customer, [
            'brand_id' => $brand->id,
            'property_id' => $property->id,
            'job_type' => currentCompany()->vertical->jobTypes()[0]->value,
            'description' => mb_substr(implode(', ', array_column($totals['lines'], 'description')), 0, 1000),
        ], [], [], null, null, $user);

        $now = CarbonImmutable::now(currentCompany()->timezone);
        $visit = $this->saveVisit->handle($job, null, ['scheduled_start' => $now->utc(), 'scheduled_end' => $now->addMinutes(30)->utc()], [$user->id], $user);
        $visit->forceFill(['status' => VisitStatus::Completed, 'started_at' => $now->utc(), 'finished_at' => now()])->save();

        $job->refresh();
        $job->outcome = JobOutcome::Repaired;
        $this->changeStatus->handle($job, JobStatus::Completed, $user, $visit);

        return $job->refresh();
    }
}
