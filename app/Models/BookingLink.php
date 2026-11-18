<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A self-booking link (owner's task of November 19): /book/{token}, no sign-in, valid 7 days and for one booking.
 * Made by the AI's reply draft when a customer asks about a time or asks to come; known name and phone are filled in.
 *
 * @property int $id
 * @property int $company_id
 * @property string $token
 * @property int|null $customer_id
 * @property string|null $phone E.164
 * @property string|null $name
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $service_job_id
 * @property int|null $created_by
 * @property-read Customer|null $customer
 */
class BookingLink extends Model
{
    use BelongsToCompany;

    public const DAYS = 7;

    protected $fillable = ['token', 'customer_id', 'phone', 'name', 'expires_at', 'used_at', 'service_job_id', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
