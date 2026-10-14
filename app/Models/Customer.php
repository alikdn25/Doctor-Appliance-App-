<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\PaymentTerms;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\RecordsChanges;
use App\Support\NameAvatar;
use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property CustomerType $type
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string $display_name
 * @property LeadSource|null $lead_source
 * @property PaymentTerms|null $payment_terms Null = the company default
 * @property list<string> $tags
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Customer extends Model
{
    use BelongsToCompany, RecordsChanges;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    public const AUDIT_TYPE = 'customer';

    /** Days a deleted customer can still be restored from Deleted items. */
    public const RESTORE_DAYS = 30;

    /** When the customer being restored was deleted (its properties deleted with it share the time). */
    private ?string $deletedTogetherAt = null;

    protected $fillable = [
        'type',
        'first_name',
        'avatar_style',
        'last_name',
        'company_name',
        'lead_source',
        'payment_terms',
        'tags',
        'notes',
    ];

    protected $attributes = [
        'type' => 'residential',
        'tags' => '[]',
        'avatar_style' => 'auto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'lead_source' => LeadSource::class,
            'payment_terms' => PaymentTerms::class,
            'tags' => 'array',
            'has_second_contact' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->display_name = $customer->buildDisplayName();
        });

        // A phone named after the customer is not a second person: recheck when the name changes.
        static::updated(function (Customer $customer) {
            if ($customer->wasChanged('first_name')) {
                CustomerPhone::refreshSecondContact($customer->id);
            }
        });

        // Soft-delete the customer's properties (and through them, appliances) with it, at the same moment, so that
        // restoring the customer brings back exactly what was deleted with it.
        static::deleted(function (Customer $customer) {
            if (! $customer->isForceDeleting() && $customer->deleted_at !== null) {
                Property::withoutCompanyScope()->where('company_id', $customer->company_id)->where('customer_id', $customer->id)
                    ->get()->each(fn (Property $property) => $property->softDeleteAt($customer->deleted_at));
            }
        });

        static::restoring(function (Customer $customer) {
            $customer->deletedTogetherAt = $customer->getRawOriginal('deleted_at');
        });

        static::restored(function (Customer $customer) {
            if ($customer->deletedTogetherAt !== null) {
                Property::withoutCompanyScope()->onlyTrashed()
                    ->where('company_id', $customer->company_id)
                    ->where('customer_id', $customer->id)
                    ->where('deleted_at', $customer->deletedTogetherAt)
                    ->get()
                    ->each(fn (Property $property) => $property->restore());
            }
        });
    }

    /** Decorative icon; the saved manual choice takes precedence. */
    public function avatarIcon(): string
    {
        if ($this->type !== CustomerType::Residential) {
            return 'business';
        }

        if ($this->avatar_style === 'auto') {
            return $this->has_second_contact ? 'couple' : NameAvatar::suggest($this->first_name);
        }

        return $this->avatar_style;
    }

    /** The customer's own payment terms, or the company default. */
    public function paymentTerms(): PaymentTerms
    {
        return $this->payment_terms ?? currentCompany()->default_payment_terms;
    }

    /**
     * Business name for commercial customers, otherwise the person's name.
     */
    public function buildDisplayName(): string
    {
        $person = trim("{$this->first_name} {$this->last_name}");
        $business = trim((string) $this->company_name);

        if ($business !== '' && ($person === '' || $this->type !== CustomerType::Residential)) {
            return $business;
        }

        return $person !== '' ? $person : $business;
    }

    /**
     * @return HasMany<CustomerPhone, $this>
     */
    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasOne<CustomerPhone, $this>
     */
    public function primaryPhone(): HasOne
    {
        return $this->hasOne(CustomerPhone::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasMany<CustomerEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(CustomerEmail::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasOne<CustomerEmail, $this>
     */
    public function primaryEmail(): HasOne
    {
        return $this->hasOne(CustomerEmail::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasOne<Property, $this>
     */
    public function primaryProperty(): HasOne
    {
        return $this->hasOne(Property::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * @return HasManyThrough<Appliance, Property, $this>
     */
    public function appliances(): HasManyThrough
    {
        return $this->hasManyThrough(Appliance::class, Property::class);
    }

    /**
     * Search by name, phone (any format), email, address, appliance brand, model or serial number.
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = PhoneNumber::searchDigits($term);

        $query->where(function (Builder $q) use ($like, $digits) {
            $q->where('display_name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)
                ->orWhereHas('emails', fn (Builder $e) => $e->where('email', 'ilike', $like))
                ->orWhereHas('properties', fn (Builder $p) => $p->where(fn (Builder $a) => $a
                    ->where('line1', 'ilike', $like)
                    ->orWhere('line2', 'ilike', $like)
                    ->orWhere('unit', 'ilike', $like)
                    ->orWhere('city', 'ilike', $like)
                    ->orWhere('postal_code', 'ilike', $like)
                    ->orWhere('label', 'ilike', $like)))
                ->orWhereHas('appliances', fn (Builder $a) => $a->where(fn (Builder $m) => $m
                    ->where('model_number', 'ilike', $like)
                    ->orWhere('serial_number', 'ilike', $like)
                    ->orWhere('manufacturer', 'ilike', $like)));

            if (strlen($digits) >= 3) {
                $q->orWhereHas('phones', fn (Builder $p) => $p->where('number_normalized', 'like', "%{$digits}%"));
            }
        });
    }
}
