<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsBillingLine;
use Illuminate\Database\Eloquent\Model;

/**
 * A line of an estimate. Prices in cents; total = quantity × unit price, rounded to the cent.
 *
 * @property int $id
 * @property int $company_id
 * @property int $estimate_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property int $unit_price
 * @property bool $taxable
 * @property int $total
 * @property bool $optional The customer may choose to include it
 * @property bool $selected Whether an optional line is included
 */
class EstimateItem extends Model
{
    use BelongsToCompany, IsBillingLine;

    protected $fillable = ['position', 'description', 'quantity', 'unit_price', 'taxable', 'total', 'optional', 'selected'];

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
            'optional' => 'boolean',
            'selected' => 'boolean',
        ];
    }

    /**
     * Counted in the totals: every required line, and optional lines that are selected.
     */
    public function isIncluded(): bool
    {
        return ! $this->optional || $this->selected;
    }
}
