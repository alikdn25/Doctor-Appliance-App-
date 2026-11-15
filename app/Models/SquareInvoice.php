<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * An invoice imported from Square ("Imported from Square"): history only. It cannot be edited, has no job, and is
 * left out of the tax reserve and of the app's own invoice lists; it shows in the customer's history.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $customer_id
 * @property int $square_import_id
 * @property string $square_invoice_id
 * @property string|null $number
 * @property string $status
 * @property Carbon|null $issued_on
 * @property Carbon|null $due_on
 * @property string $currency
 * @property int $subtotal
 * @property int $tax_total
 * @property int $total
 * @property int $amount_paid
 * @property list<array{name: string, quantity: string, amount: int, tax: int}> $items
 * @property list<array{name: string, rate: string|null, amount: int}> $taxes
 * @property list<array{amount: int, method: string, paid_at: string|null}> $payments
 * @property string|null $square_updated_at
 * @property Carbon|null $deleted_at
 * @property-read Customer|null $customer
 */
class SquareInvoice extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'items' => 'array',
            'taxes' => 'array',
            'payments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    /** Name in Deleted items and the change log. */
    public function auditLabel(): string
    {
        return __('square_import.invoice_label', ['number' => $this->number ?? $this->square_invoice_id]);
    }
}
