<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer read from Square during an import, normalised (phone in E.164), with the customer in the book it
 * matches (by Square id, phone or email) and the Owner's choice: merge, skip or create new.
 *
 * @property int $id
 * @property int $company_id
 * @property int $square_import_id
 * @property string $square_id
 * @property array<string, mixed> $data
 * @property string|null $square_updated_at
 * @property string $kind new | match | linked
 * @property int|null $customer_id
 * @property string|null $matched_by square_id | phone | email
 * @property string|null $decision merge | skip | new
 * @property bool $has_contact
 * @property-read Customer|null $customer
 */
class SquareImportCustomer extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'has_contact' => 'boolean'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
