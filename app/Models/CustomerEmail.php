<?php

namespace App\Models;

use App\Enums\EmailLabel;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property EmailLabel $label
 * @property string $email
 * @property bool $is_primary
 */
class CustomerEmail extends Model
{
    /** Change log type (owner's task of November 25: every correction is logged). */
    public const AUDIT_TYPE = 'email';

    use BelongsToCompany, RecordsChanges;

    protected $fillable = ['label', 'email', 'is_primary'];

    protected $attributes = [
        'label' => 'personal',
        'is_primary' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'label' => EmailLabel::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
