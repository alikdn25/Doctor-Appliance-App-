<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money the Owner moved to the tax account for a month (UX-REDESIGN §17). The transfer itself happens in the bank.
 *
 * @property int $id
 * @property int $company_id
 * @property Carbon $month First day of the month
 * @property int $amount Minor units
 * @property string $currency
 * @property Carbon $transferred_on
 * @property string|null $note
 * @property int|null $created_by
 * @property-read User|null $creator
 */
class TaxReserveTransfer extends Model
{
    use BelongsToCompany;

    protected $fillable = ['month', 'amount', 'currency', 'transferred_on', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'amount' => 'integer', 'transferred_on' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
