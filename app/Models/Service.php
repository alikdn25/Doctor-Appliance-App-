<?php

namespace App\Models;

use App\Enums\LineKind;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Jobs\ServiceDefaults;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An item of the company's price book (SPEC §7.11): a service, part or material, with price, cost and a default
 * warranty. The starting services come from the company's vertical.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property list<int> $brand_ids Empty means available to every brand in the company
 * @property int|null $unit_price Minor units of the company currency; null = not set yet
 * @property bool $taxable
 * @property bool $is_active
 * @property int $sort_order
 * @property LineKind $kind
 * @property string|null $part_number
 * @property string|null $supplier
 * @property string|null $unit
 * @property int|null $unit_cost
 * @property int|null $warranty_value Default warranty of lines made from it (0 = none, null = company default)
 * @property string|null $warranty_unit
 */
class Service extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name', 'description', 'category', 'brand_ids', 'unit_price', 'taxable', 'is_active', 'sort_order',
        'kind', 'part_number', 'supplier', 'unit', 'unit_cost', 'warranty_value', 'warranty_unit',
    ];

    protected $attributes = [
        'taxable' => true,
        'is_active' => true,
        'sort_order' => 0,
        'kind' => 'service',
        'brand_ids' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'brand_ids' => 'array',
            'taxable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'kind' => LineKind::class,
            'unit_cost' => 'integer',
            'warranty_value' => 'integer',
        ];
    }

    /** @param Builder<Service> $query */
    public function scopeAvailableForBrand(Builder $query, int $brandId): void
    {
        $query->where(fn ($q) => $q->whereJsonLength('brand_ids', 0)->orWhereJsonContains('brand_ids', $brandId));
    }

    /**
     * Creates the starting services of the current company's vertical (once).
     */
    public static function createDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (ServiceDefaults::forVertical(currentCompany()->vertical) as $position => $item) {
            static::query()->create([...$item, 'sort_order' => $position + 1]);
        }
    }
}
