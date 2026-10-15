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
 * @property bool $selected Whether an optional line is included, or the line's option is the chosen one
 * @property string|null $option_name good, better or best; null = in every option
 */
class EstimateItem extends Model
{
    use BelongsToCompany, IsBillingLine;

    /** Good / Better / Best: the options an estimate can offer, in this order. */
    public const OPTIONS = ['good', 'better', 'best'];

    protected $fillable = ['position', 'description', 'quantity', 'unit_price', 'taxable', 'total', 'optional', 'selected', 'option_name'];

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
     * Counted in the totals: every required line, optional lines that are selected, and the lines of the chosen
     * option.
     */
    public function isIncluded(): bool
    {
        if ($this->option_name !== null) {
            return $this->selected;
        }

        return ! $this->optional || $this->selected;
    }
}
