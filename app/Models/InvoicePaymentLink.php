<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider's checkout link for (the balance of) an invoice. Matched back to the invoice by the
 * provider's order ID when the payment webhook arrives.
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property string $provider
 * @property string $provider_link_id
 * @property string|null $provider_order_id
 * @property string $url
 * @property int $amount Minor units of $currency
 * @property string $currency
 * @property string $status active, paid or replaced
 * @property int|null $created_by
 * @property-read Invoice $invoice
 */
class InvoicePaymentLink extends Model
{
    use BelongsToCompany;

    protected $table = 'payment_links';

    public const ACTIVE = 'active';

    public const PAID = 'paid';

    public const REPLACED = 'replaced';

    protected $fillable = ['provider', 'provider_link_id', 'provider_order_id', 'url', 'amount', 'currency', 'status', 'created_by'];

    protected $attributes = ['status' => self::ACTIVE];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }
}
