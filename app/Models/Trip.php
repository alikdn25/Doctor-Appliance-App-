<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A business trip in the mileage log. Trips to customers are made from the day's route when a job is started
 * (previous job's address → this job's address); other trips are added by hand. Distance in kilometres.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property Carbon $trip_date
 * @property string $type client, parts_store, supplier or other
 * @property string|null $from_address
 * @property string|null $to_address
 * @property string|null $distance_km
 * @property string|null $purpose
 * @property int|null $service_job_id
 * @property int|null $job_visit_id
 * @property bool $distance_edited
 * @property bool $is_draft The day's route measured at the end of the day; counted once the Owner confirms it
 * @property-read User|null $user
 * @property-read ServiceJob|null $job
 */
class Trip extends Model
{
    /** Change log type (owner's task of November 25: every correction is logged). */
    public const AUDIT_TYPE = 'trip';

    use BelongsToCompany, RecordsChanges, SoftDeletes;

    public const TYPES = ['client', 'parts_store', 'supplier', 'other'];

    protected $fillable = [
        'user_id', 'trip_date', 'type', 'from_address', 'to_address', 'distance_km', 'purpose',
        'service_job_id', 'job_visit_id', 'distance_edited', 'is_draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['trip_date' => 'date:Y-m-d', 'distance_km' => 'decimal:1', 'distance_edited' => 'boolean', 'is_draft' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id')->withTrashed();
    }
}
