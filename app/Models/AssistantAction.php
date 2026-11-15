<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An action the assistant proposed ("what I will do"): a payment reminder, moving a booking or a new booking. It runs
 * only when the person presses Confirm; Cancel drops it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $conversation
 * @property string $type
 * @property array<string, mixed> $params
 * @property string $summary
 * @property string $status
 * @property string|null $result
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
class AssistantAction extends Model
{
    use BelongsToCompany;

    public const PAYMENT_REMINDER = 'payment_reminder';

    public const RESCHEDULE = 'reschedule';

    public const BOOKING = 'booking';

    protected $fillable = ['user_id', 'conversation', 'type', 'params', 'summary', 'status', 'result', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['params' => 'array', 'decided_at' => 'datetime'];
    }
}
