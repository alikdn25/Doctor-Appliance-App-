<?php

namespace App\Models;

use App\Enums\ApplianceType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ApplianceFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $property_id
 * @property ApplianceType $type
 * @property string|null $manufacturer
 * @property string|null $model_number
 * @property string|null $serial_number
 * @property string|null $rating_plate_path
 * @property Carbon|null $install_date
 * @property Carbon|null $purchase_date
 * @property Carbon|null $warranty_expires_on
 * @property string|null $warranty_notes
 * @property string|null $notes
 * @property-read string|null $rating_plate_url
 */
class Appliance extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ApplianceFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'manufacturer',
        'model_number',
        'serial_number',
        'install_date',
        'purchase_date',
        'warranty_expires_on',
        'warranty_notes',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApplianceType::class,
            'install_date' => 'date:Y-m-d',
            'purchase_date' => 'date:Y-m-d',
            'warranty_expires_on' => 'date:Y-m-d',
        ];
    }

    /**
     * Model and serial numbers are stored without surrounding spaces and in upper case.
     *
     * @return Attribute<never, string|null>
     */
    protected function modelNumber(): Attribute
    {
        return Attribute::set(fn (?string $value) => $this->cleanCode($value));
    }

    /**
     * @return Attribute<never, string|null>
     */
    protected function serialNumber(): Attribute
    {
        return Attribute::set(fn (?string $value) => $this->cleanCode($value));
    }

    private function cleanCode(?string $value): ?string
    {
        $value = mb_strtoupper(trim((string) $value));

        return $value === '' ? null : $value;
    }

    public function isUnderWarranty(): bool
    {
        return $this->warranty_expires_on !== null && ! $this->warranty_expires_on->copy()->endOfDay()->isPast();
    }

    /**
     * The rating plate photo is private: served by an authorized route. The file name in the query
     * changes with every new photo, so browsers don't keep showing the old one.
     *
     * @return Attribute<string|null, never>
     */
    protected function ratingPlateUrl(): Attribute
    {
        return Attribute::get(fn () => $this->rating_plate_path
            ? route('appliances.rating-plate', [$this->id, 'v' => pathinfo($this->rating_plate_path, PATHINFO_FILENAME)])
            : null);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function label(): string
    {
        return collect([$this->manufacturer, $this->type->label()])->filter()->implode(' ');
    }
}
