<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company-defined tax (e.g. GST 5%, PST 7%). Rates are settings, never hard-coded.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $rate Percentage, e.g. "5.0000"
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
class TaxRate extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'rate', 'is_default', 'is_active', 'sort_order'];

    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
