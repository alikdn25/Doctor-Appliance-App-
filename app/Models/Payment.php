<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment towards an invoice, recorded by hand or by a payment provider. A deposit paid online when the customer
 * approves an estimate has estimate_id and no invoice yet; it moves to the invoice made from the estimate.
 * Payments are never deleted: a mistake is voided and stays in the history.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $invoice_id
 * @property int|null $estimate_id Set on deposits paid on an estimate
 * @property int $amount Minor units of $currency
 * @property string $currency ISO 4217 (the invoice's currency)
 * @property int $tip_amount Tip taken by the provider, not applied to the invoice (negative on a refund)
 * @property int|null $refunded_payment_id Set on a refund row (negative amount)
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $note
 * @property Carbon $received_at
 * @property int|null $user_id
 * @property string|null $provider
 * @property string|null $provider_payment_id
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property-read Invoice|null $invoice
 * @property-read Estimate|null $estimate
 * @property-read User|null $user
 */
class Payment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['amount', 'tip_amount', 'method', 'reference', 'note', 'received_at', 'provider', 'provider_payment_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tip_amount' => 'integer',
            'method' => PaymentMethod::class,
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isRefund(): bool
    {
        return $this->refunded_payment_id !== null;
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @param  Builder<Payment>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNull('voided_at');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
