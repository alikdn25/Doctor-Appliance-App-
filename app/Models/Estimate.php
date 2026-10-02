<?php

namespace App\Models;

use App\Enums\EstimateStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsBillingDocument;
use Carbon\CarbonImmutable;
use Database\Factories\EstimateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int $brand_id
 * @property int $customer_id
 * @property int|null $property_id
 * @property string $number
 * @property string $currency ISO 4217; amounts are in its minor units
 * @property bool $prices_include_tax
 * @property string|null $public_token Key of the customer's online page
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property Carbon|null $viewed_at
 * @property EstimateStatus $status
 * @property Carbon $issued_on
 * @property Carbon|null $valid_until
 * @property string|null $discount_type
 * @property string $discount_value
 * @property int $subtotal
 * @property int $discount_total
 * @property int $tax_total
 * @property int $total
 * @property list<array{tax_rate_id: int|null, name: string, rate: string, compound?: bool, amount: int}> $taxes
 * @property string|null $notes
 * @property Carbon|null $approved_at
 * @property Carbon|null $declined_at
 * @property string|null $deposit_type percent or amount; null = no deposit
 * @property string $deposit_value
 * @property int $deposit_amount The deposit for the current total, in minor units
 * @property string|null $signer_name Set when the customer approved online
 * @property string|null $signature_type drawn or typed
 * @property string|null $signature_path PNG of a drawn signature on the private disk
 * @property string|null $approved_ip
 * @property string|null $approved_user_agent
 * @property string|null $decline_reason
 * @property string|null $declined_ip
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read ServiceJob $job
 * @property-read Customer $customer
 * @property-read Brand $brand
 * @property-read Invoice|null $invoice
 */
class Estimate extends Model
{
    use BelongsToCompany, IsBillingDocument;

    /** @use HasFactory<EstimateFactory> */
    use HasFactory, SoftDeletes;

    public const SIGNATURE_DRAWN = 'drawn';

    public const SIGNATURE_TYPED = 'typed';

    protected $fillable = ['issued_on', 'valid_until', 'discount_type', 'discount_value', 'notes', 'deposit_type', 'deposit_value'];

    protected $attributes = [
        'status' => 'draft',
        'taxes' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->documentCasts(),
            'status' => EstimateStatus::class,
            'valid_until' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'deposit_value' => 'decimal:2',
            'deposit_amount' => 'integer',
        ];
    }

    /**
     * @return HasMany<EstimateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Deposit payments (and their refunds) made on this estimate. They move to the invoice made from it,
     * keeping estimate_id, so they still count here.
     *
     * @return HasMany<Payment, $this>
     */
    public function depositPayments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Net deposit received (refunds deducted), in minor units.
     */
    public function depositPaid(): int
    {
        return (int) $this->depositPayments()->valid()->sum('amount');
    }

    /**
     * What is still to pay of the deposit.
     */
    public function depositDue(): int
    {
        return max(0, $this->deposit_amount - $this->depositPaid());
    }

    /**
     * The deposit for a total: a percent of it, or a fixed amount (never more than the total).
     */
    public function depositFor(int $total, int $minorFactor): int
    {
        $value = (float) $this->deposit_value;

        if ($total <= 0 || $value <= 0) {
            return 0;
        }

        $amount = match ($this->deposit_type) {
            'percent' => (int) round($total * min($value, 100) / 100),
            'amount' => (int) round($value * $minorFactor),
            default => 0,
        };

        return min($amount, $total);
    }

    /**
     * Past its "valid until" day in the company's time zone: it can no longer be approved.
     */
    public function isExpired(): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $this->valid_until->toDateString() < CarbonImmutable::now(currentCompany()->timezone)->toDateString();
    }

    /**
     * The customer approved and signed on the online page (as opposed to staff marking it approved).
     */
    public function approvedOnline(): bool
    {
        return $this->signer_name !== null && $this->approved_at !== null;
    }

    /**
     * The customer can still approve or decline on the online page.
     */
    public function awaitsCustomer(): bool
    {
        return in_array($this->status, [EstimateStatus::Draft, EstimateStatus::Declined], true) && ! $this->trashed();
    }
}
