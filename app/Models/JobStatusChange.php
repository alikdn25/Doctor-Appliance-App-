<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Log entry for every job status change: who and when.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int|null $job_visit_id
 * @property JobStatus|null $from_status
 * @property JobStatus $to_status
 * @property int|null $user_id
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class JobStatusChange extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = [
        'service_job_id',
        'job_visit_id',
        'from_status',
        'to_status',
        'user_id',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => JobStatus::class,
            'to_status' => JobStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
