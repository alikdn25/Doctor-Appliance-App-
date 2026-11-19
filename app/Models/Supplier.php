<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A parts supplier of the company (Settings → Suppliers): a name and an address. The Parts page groups the parts to
 * order by supplier, and an automatic "Buy at {supplier}" task gets the supplier's address.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 */
class Supplier extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['name', 'address'];

    protected static function booted(): void
    {
        // A changed address is placed on the map again.
        static::saving(function (Supplier $supplier) {
            if ($supplier->isDirty('address')) {
                $supplier->latitude = null;
                $supplier->longitude = null;
            }
        });
    }

    /**
     * @return HasMany<JobPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(JobPart::class);
    }

    /**
     * @return list<array{value: string, label: string, address: string|null}>
     */
    public static function options(): array
    {
        return self::query()->orderBy('name')->get()
            ->map(fn (self $s) => ['value' => (string) $s->id, 'label' => $s->name, 'address' => $s->address])
            ->values()->all();
    }
}
