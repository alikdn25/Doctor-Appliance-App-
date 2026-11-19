<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One part a job needs (owner's task of November 19): to order → ordered (with the order date) → on hand. A job can
 * need several parts; the job's status follows them (JobParts::syncStatus).
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property string $name
 * @property string|null $part_number
 * @property bool $number_unverified The AI's number, not corrected or confirmed yet ("check").
 * @property int $quantity
 * @property int|null $supplier_id
 * @property string $status
 * @property Carbon|null $ordered_on
 * @property Carbon|null $received_at
 * @property int|null $created_by
 * @property-read ServiceJob $job
 * @property-read Supplier|null $supplier
 */
class JobPart extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const TO_ORDER = 'to_order';

    public const ORDERED = 'ordered';

    public const ON_HAND = 'on_hand';

    public const STATUSES = [self::TO_ORDER, self::ORDERED, self::ON_HAND];

    /** Waiting longer than this many days is highlighted. */
    public const LONG_WAIT_DAYS = 7;

    protected $fillable = ['service_job_id', 'name', 'part_number', 'number_unverified', 'quantity', 'supplier_id', 'status', 'ordered_on', 'received_at', 'created_by'];

    protected $attributes = ['status' => self::TO_ORDER, 'quantity' => 1, 'number_unverified' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number_unverified' => 'boolean',
            'quantity' => 'integer',
            'ordered_on' => 'date:Y-m-d',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }
}
