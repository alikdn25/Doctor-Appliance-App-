<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company-defined tax (e.g. GST 5%, state sales tax 6.25%, VAT 20%). Rates are settings, never hard-coded.
 * A compound tax is charged on the amount plus the taxes listed before it (sort order).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $rate Percentage, e.g. "5.0000"
 * @property bool $is_compound
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
class TaxRate extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'rate', 'is_compound', 'is_default', 'is_active', 'sort_order'];

    protected $attributes = [
        'is_compound' => false,
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
            'is_compound' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
