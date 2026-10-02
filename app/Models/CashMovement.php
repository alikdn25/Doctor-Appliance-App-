<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A movement of the cash a technician holds: collected from a customer (+), handed to the office (−), or a
 * reversal of an earlier movement (with the reason). Never deleted or edited.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id The technician holding the cash
 * @property string $type collected, deposit or reversal
 * @property int $amount Signed, minor units
 * @property string $currency
 * @property Carbon $occurred_on
 * @property int|null $payment_id
 * @property int|null $reverses_id
 * @property string|null $note
 * @property int|null $created_by
 * @property-read User $user
 * @property-read User|null $creator
 * @property-read Payment|null $payment
 */
class CashMovement extends Model
{
    use BelongsToCompany;

    public const COLLECTED = 'collected';

    public const DEPOSIT = 'deposit';

    public const REVERSAL = 'reversal';

    protected $fillable = ['user_id', 'type', 'amount', 'currency', 'occurred_on', 'payment_id', 'reverses_id', 'note', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'occurred_on' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
