<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsBillingDocument;
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
    use BelongsToCompany, IsBillingDocument;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, SoftDeletes;

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
            ...$this->documentCasts(),
            'status' => InvoiceStatus::class,
            'due_on' => 'date:Y-m-d',
            'amount_paid' => 'integer',
            'balance' => 'integer',
            'credited_amount' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
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
}
