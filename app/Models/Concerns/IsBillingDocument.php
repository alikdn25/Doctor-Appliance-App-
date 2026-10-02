<?php

namespace App\Models\Concerns;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared parts of estimates and invoices: the job they belong to and its customer, brand and property.
 *
 * @mixin Model
 */
trait IsBillingDocument
{
    /**
     * @return array<string, string>
     */
    protected function documentCasts(): array
    {
        return [
            'issued_on' => 'date:Y-m-d',
            'discount_value' => 'decimal:2',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'taxes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
