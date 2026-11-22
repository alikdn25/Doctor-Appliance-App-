<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One try to take money on the Take payment screen (owner's task of November 21): an invoice's balance (or part of
 * it), or a charge without a booking (draft: what was done, amount, tax mode, customer). A card reader charge stays
 * pending until the Square payment comes back (from the phone or the webhook, whichever is first); its reference is
 * the Square payment's reference_id. Cash and E-transfer are paid at once. Paid once only.
 *
 * @property int $id
 * @property int $company_id
 * @property string $reference
 * @property int|null $user_id
 * @property int|null $invoice_id
 * @property int|null $payment_id
 * @property int $amount Minor units of $currency
 * @property string $currency
 * @property PaymentMethod $method
 * @property string $status pending | paid | failed
 * @property array<string, mixed>|null $draft
 * @property string|null $square_reference The Square reference_id: the invoice number (reserved for a charge without a booking)
 * @property string|null $provider_payment_id
 * @property string|null $error
 * @property Carbon|null $paid_at
 * @property-read Invoice|null $invoice
 * @property-read Payment|null $payment
 * @property-read User|null $user
 */
class Charge extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = ['amount', 'currency', 'method', 'draft', 'invoice_id', 'user_id'];

    protected $attributes = ['status' => self::PENDING];

    protected static function booted(): void
    {
        static::creating(function (Charge $charge) {
            $charge->reference ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'draft' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}
