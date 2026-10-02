<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $brand_id
 * @property string|null $label
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country
 * @property bool $is_primary
 */
class BrandAddress extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'label',
        'line1',
        'line2',
        'city',
        'region',
        'postal_code',
        'country',
        'is_primary',
    ];

    protected $attributes = [
        'is_primary' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (BrandAddress $address) {
            $address->country = strtoupper($address->country ?: PhoneNumber::defaultCountry());
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
