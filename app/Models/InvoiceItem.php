<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of an invoice. Prices in cents; total = quantity × unit price, rounded to the cent.
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property int $unit_price
 * @property bool $taxable
 * @property int $total
 */
class InvoiceItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['position', 'description', 'quantity', 'unit_price', 'taxable', 'total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:2',
            'unit_price' => 'integer',
            'taxable' => 'boolean',
            'total' => 'integer',
        ];
    }
}
