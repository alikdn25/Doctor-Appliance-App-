<?php

namespace App\Models;

use App\Enums\PhoneLabel;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property PhoneLabel $label
 * @property string $number
 * @property string $number_normalized
 * @property bool $is_primary
 */
class CustomerPhone extends Model
{
    use BelongsToCompany;

    protected $fillable = ['label', 'number', 'is_primary'];

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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomerPhone $phone) {
            $phone->number = trim($phone->number);
            $phone->number_normalized = PhoneNumber::normalize($phone->number);
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
