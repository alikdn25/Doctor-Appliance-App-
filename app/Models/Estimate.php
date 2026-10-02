<?php

namespace App\Models;

use App\Enums\EstimateStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\IsBillingDocument;
use Database\Factories\EstimateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int $brand_id
 * @property int $customer_id
 * @property int|null $property_id
 * @property string $number
 * @property string $currency ISO 4217; amounts are in its minor units
 * @property bool $prices_include_tax
 * @property EstimateStatus $status
 * @property Carbon $issued_on
 * @property Carbon|null $valid_until
 * @property string|null $discount_type
 * @property string $discount_value
 * @property int $subtotal
 * @property int $discount_total
 * @property int $tax_total
 * @property int $total
 * @property list<array{tax_rate_id: int|null, name: string, rate: string, compound?: bool, amount: int}> $taxes
 * @property string|null $notes
 * @property Carbon|null $approved_at
 * @property Carbon|null $declined_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read ServiceJob $job
 * @property-read Customer $customer
 * @property-read Brand $brand
 * @property-read Invoice|null $invoice
 */
class Estimate extends Model
{
    use BelongsToCompany, IsBillingDocument;

    /** @use HasFactory<EstimateFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['issued_on', 'valid_until', 'discount_type', 'discount_value', 'notes'];

    protected $attributes = [
        'status' => 'draft',
        'taxes' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->documentCasts(),
            'status' => EstimateStatus::class,
            'valid_until' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<EstimateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
