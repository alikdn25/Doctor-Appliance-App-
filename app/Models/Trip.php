<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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
 * @property-read User|null $user
 * @property-read ServiceJob|null $job
 */
class Trip extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const TYPES = ['client', 'parts_store', 'supplier', 'other'];

    protected $fillable = [
        'user_id', 'trip_date', 'type', 'from_address', 'to_address', 'distance_km', 'purpose',
        'service_job_id', 'job_visit_id', 'distance_edited',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['trip_date' => 'date:Y-m-d', 'distance_km' => 'decimal:1', 'distance_edited' => 'boolean'];
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
