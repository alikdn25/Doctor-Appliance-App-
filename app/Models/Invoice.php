<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasReportPhotos;
use App\Models\Concerns\IsBillingDocument;
use App\Models\Concerns\RecordsChanges;
use Carbon\CarbonImmutable;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int $brand_id
 * @property int $customer_id
 * @property int|null $property_id
 * @property int|null $estimate_id
 * @property string $number
 * @property string $currency ISO 4217; amounts are in its minor units
 * @property bool $prices_include_tax
 * @property string|null $public_token Key of the customer's online page
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property Carbon|null $viewed_at
 * @property Carbon|null $first_opened_at The customer first followed the invoice link (/i/{token})
 * @property Carbon|null $last_opened_at
 * @property InvoiceStatus $status
 * @property Carbon $issued_on
 * @property Carbon|null $due_on
 * @property string|null $discount_type
 * @property string $discount_value
 * @property int $subtotal
 * @property int $discount_total
 * @property int $tax_total
 * @property int $total
 * @property int $amount_paid
 * @property int $balance
 * @property int $credited_amount Refunded money the customer no longer owes (warranty refunds …)
 * @property list<array{tax_rate_id: int|null, name: string, rate: string, compound?: bool, amount: int}> $taxes
 * @property string|null $notes
 * @property Carbon|null $paid_at
 * @property Carbon|null $reminder_sent_at Soft payment reminder sent
 * @property int|null $reminder_message_id
 * @property Carbon|null $reminder_answered_at
 * @property Carbon|null $review_offered_at "Send a review request?" offered 2 hours after it was paid
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read ServiceJob $job
 * @property-read Customer $customer
 * @property-read Brand $brand
 * @property-read Estimate|null $estimate
 */
class Invoice extends Model
{
    use BelongsToCompany, HasReportPhotos, IsBillingDocument, RecordsChanges;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, SoftDeletes;

    public const AUDIT_TYPE = 'invoice';

    public const REPORT_TYPE = 'invoice';

    protected $fillable = ['issued_on', 'due_on', 'discount_type', 'discount_value', 'notes'];

    protected $attributes = [
        'status' => 'unpaid',
        'taxes' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_after_send_at' => 'datetime',
            'has_report' => 'boolean',
            ...$this->documentCasts(),
            'status' => InvoiceStatus::class,
            'due_on' => 'date:Y-m-d',
            'amount_paid' => 'integer',
            'balance' => 'integer',
            'credited_amount' => 'integer',
            'paid_at' => 'datetime',
            'review_offered_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'reminder_answered_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Revenue without taxes once settled refunds are taken back (owner's task of November 26): a refund with its own
     * taxes takes back its amount without them; older credits (no taxes kept) lower the revenue in proportion.
     */
    public function revenueAfterRefunds(): int
    {
        $net = $this->total - $this->tax_total;

        if ($this->credited_amount <= 0 || $this->total <= 0) {
            return $net;
        }

        [$explicitTotal, $explicitTaxes] = $this->explicitRefunds();
        $rest = max(0, $this->credited_amount - $explicitTotal);

        return max(0, $net - ($explicitTotal - array_sum($explicitTaxes)) - (int) round($net * $rest / $this->total));
    }

    /**
     * The invoice's taxes by name less what settled refunds took back.
     *
     * @return array<string, int>
     */
    public function taxesAfterRefunds(): array
    {
        $taxes = [];
        foreach ((array) $this->taxes as $tax) {
            $taxes[(string) $tax['name']] = ($taxes[(string) $tax['name']] ?? 0) + (int) ($tax['amount'] ?? 0);
        }

        if ($this->credited_amount <= 0 || $this->total <= 0) {
            return $taxes;
        }

        [$explicitTotal, $explicitTaxes] = $this->explicitRefunds();
        $rest = max(0, $this->credited_amount - $explicitTotal);

        return collect($taxes)->map(fn (int $amount, string $name) => $amount - ($explicitTaxes[$name] ?? 0) - (int) round($amount * $rest / $this->total))->all();
    }

    /**
     * Settled refunds that kept their taxes: their total and their taxes by name.
     *
     * @return array{0: int, 1: array<string, int>}
     */
    private function explicitRefunds(): array
    {
        $total = 0;
        $taxes = [];

        Payment::query()->valid()->where('invoice_id', $this->id)->whereNotNull('refunded_payment_id')->whereNotNull('refund_taxes')
            ->get(['amount', 'refund_taxes'])
            ->each(function (Payment $refund) use (&$total, &$taxes) {
                $total += abs((int) $refund->amount);
                foreach ((array) $refund->refund_taxes as $tax) {
                    $taxes[(string) $tax['name']] = ($taxes[(string) $tax['name']] ?? 0) + (int) $tax['amount'];
                }
            });

        return [$total, $taxes];
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    /**
     * Money is still owed after the due date (in the company's time zone).
     */
    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && in_array($this->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)
            && $this->balance > 0
            && $this->due_on->toDateString() < CarbonImmutable::now(currentCompany()->timezone)->toDateString();
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * All payments, voided ones included (they stay visible in the history).
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * Photos or screenshots the invoice was made from.
     *
     * @return HasMany<InvoiceAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(InvoiceAttachment::class)->orderBy('id');
    }

    /**
     * @return HasMany<InvoicePaymentLink, $this>
     */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(InvoicePaymentLink::class);
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Invoices that still expect money: not void and with a balance.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::PartiallyPaid->value]);
    }

    /**
     * Outstanding invoices past their due date (see isOverdue()).
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->outstanding()
            ->where('balance', '>', 0)
            ->whereDate('due_on', '<', CarbonImmutable::now(currentCompany()->timezone)->toDateString());
    }
}
