<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Jobs\ServiceDefaults;
use Illuminate\Database\Eloquent\Model;

/**
 * A service of the company's price book (SPEC §7.11). The starting set comes from the company's vertical;
 * the full price book (parts, costs, picker on estimate/invoice lines) is a later task.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $description
 * @property int|null $unit_price Minor units of the company currency; null = not set yet
 * @property bool $taxable
 * @property bool $is_active
 * @property int $sort_order
 */
class Service extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'description', 'unit_price', 'taxable', 'is_active', 'sort_order'];

    protected $attributes = [
        'taxable' => true,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'taxable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
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
