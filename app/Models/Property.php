<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service address of a customer. Address is entered manually for now;
 * Google Places autocomplete and geocoding (latitude/longitude) come later.
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property string|null $label
 * @property string $line1
 * @property string|null $line2
 * @property string|null $unit
 * @property string $city
 * @property string|null $province
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $access_notes
 * @property string|null $gate_code
 * @property string|null $site_contact_name
 * @property string|null $site_contact_phone
 * @property bool $is_primary
 * @property-read string $full_address
 */
class Property extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<PropertyFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'label',
        'line1',
        'line2',
        'unit',
        'city',
        'province',
        'postal_code',
        'country',
        'access_notes',
        'gate_code',
        'site_contact_name',
        'site_contact_phone',
        'is_primary',
    ];

    protected $attributes = [
        'country' => 'CA',
        'is_primary' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Property $property) {
            if (! $property->isForceDeleting()) {
                $property->appliances()->get()->each->delete();
            }
        });
    }

    /**
     * One-line address, e.g. "Unit 204, 123 Main St, Surrey, BC V3T 1A1".
     */
    public function fullAddress(): string
    {
        $street = collect([$this->unit ? __('properties.unit_prefix', ['unit' => $this->unit]) : null, $this->line1, $this->line2])
            ->filter()
            ->implode(', ');

        $region = trim("{$this->province} {$this->postal_code}");

        return collect([$street, $this->city, $region])->filter()->implode(', ');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Appliance, $this>
     */
    public function appliances(): HasMany
    {
        return $this->hasMany(Appliance::class)->orderBy('type')->orderBy('id');
    }
}
