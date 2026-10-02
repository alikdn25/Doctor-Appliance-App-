<?php

namespace App\Models;

use App\Enums\LineKind;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost of a job that is on no invoice: a part bought for a repair the customer declined, consumables, materials
 * included in an installation price. Internal only.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property LineKind $kind
 * @property string $description
 * @property string|null $part_number
 * @property string|null $supplier
 * @property string $quantity
 * @property string|null $unit
 * @property int $unit_cost
 * @property string $currency
 * @property list<array{tax_rate_id: int|null, name: string, amount: int, recoverable: bool}>|null $supplier_taxes
 * @property int|null $created_by
 */
class JobCostItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['kind', 'description', 'part_number', 'supplier', 'quantity', 'unit', 'unit_cost', 'supplier_taxes'];

    protected $attributes = ['kind' => 'part'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LineKind::class,
            'quantity' => 'decimal:2',
            'unit_cost' => 'integer',
            'supplier_taxes' => 'array',
        ];
    }

    public function totalCost(): int
    {
        $cost = (int) round((float) $this->quantity * $this->unit_cost);

        foreach ($this->supplier_taxes ?? [] as $tax) {
            if (! ($tax['recoverable'] ?? true)) {
                $cost += (int) ($tax['amount'] ?? 0);
            }
        }

        return $cost;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
