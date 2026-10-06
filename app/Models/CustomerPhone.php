<?php

namespace App\Models;

use App\Enums\PhoneLabel;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property PhoneLabel $label
 * @property string|null $contact_name Who answers this number when it is not the customer (e.g. "Anna · wife")
 * @property string $number
 * @property string $number_normalized
 * @property bool $is_primary
 * @property Carbon|null $sms_opted_out_at Replied STOP to our SMS
 */
class CustomerPhone extends Model
{
    use BelongsToCompany;

    protected $fillable = ['label', 'contact_name', 'number', 'is_primary'];

    protected $attributes = [
        'label' => 'mobile',
        'is_primary' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => PhoneLabel::class,
            'is_primary' => 'boolean',
            'sms_opted_out_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomerPhone $phone) {
            // Stored in E.164; number_normalized is kept for the search and duplicate indexes.
            $phone->number = PhoneNumber::normalize($phone->number);
            $phone->number_normalized = $phone->number;
            $phone->contact_name = filled($phone->contact_name) ? trim($phone->contact_name) : null;
        });

        // A named second person makes the customer a couple (icon with two faces).
        static::saved(fn (CustomerPhone $phone) => self::refreshSecondContact($phone->customer_id));
        static::deleted(fn (CustomerPhone $phone) => self::refreshSecondContact($phone->customer_id));
    }

    public static function refreshSecondContact(int $customerId): void
    {
        // By id, without the tenant scope: also runs from factories and queue jobs.
        $customer = Customer::query()->withoutGlobalScopes()->find($customerId);

        if ($customer === null) {
            return;
        }

        $first = mb_strtolower(trim((string) $customer->first_name));
        $named = self::query()->withoutGlobalScopes()->where('customer_id', $customerId)->whereNotNull('contact_name')->pluck('contact_name')
            // The customer's own name on a phone is not a second person.
            ->contains(fn (string $name) => mb_strtolower(strtok($name, ' ·,(') ?: $name) !== $first);

        if ($customer->has_second_contact !== $named) {
            $customer->forceFill(['has_second_contact' => $named])->saveQuietly();
        }
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
