<?php

namespace App\Models;

use App\Enums\LineKind;
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
 * @property list<string>|null $applies_to Line types (LineKind values) charged by default; null = all
 */
class TaxRate extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'rate', 'is_compound', 'is_default', 'is_active', 'sort_order', 'is_recoverable', 'applies_to'];

    protected $attributes = [
        'is_compound' => false,
        'is_default' => false,
        'is_active' => true,
        'sort_order' => 0,
        'is_recoverable' => true,
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
            'is_recoverable' => 'boolean',
            'applies_to' => 'array',
        ];
    }

    /**
     * Whether a new line of this type is charged this tax by default.
     */
    public function appliesTo(LineKind $kind): bool
    {
        return $this->applies_to === null || in_array($kind->value, $this->applies_to, true);
    }
}
