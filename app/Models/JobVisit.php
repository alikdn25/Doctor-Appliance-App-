<?php

namespace App\Models;

use App\Enums\VisitStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\JobVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One trip to the customer (diagnosis, repair after parts arrive, ...). A job has one or more visits.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property Carbon $scheduled_start
 * @property Carbon $scheduled_end
 * @property int|null $estimated_duration_minutes
 * @property VisitStatus $status
 * @property Carbon|null $on_the_way_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read ServiceJob $job
 */
class JobVisit extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<JobVisitFactory> */
    use HasFactory;

    protected $fillable = [
        'scheduled_start',
        'scheduled_end',
        'estimated_duration_minutes',
    ];

    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'on_the_way_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsToMany<User, $this, JobVisitAssignee>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'job_visit_user')
            ->using(JobVisitAssignee::class)
            ->withTimestamps()
            ->orderBy('users.name');
    }

    public function isAssigned(User $user): bool
    {
        return $this->relationLoaded('assignees')
            ? $this->assignees->contains('id', $user->id)
            : $this->assignees()->where('users.id', $user->id)->exists();
    }

    /**
     * Time on the job, from "Start" to "Finish" (or until now while in progress).
     */
    public function minutesOnJob(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) floor($this->started_at->diffInSeconds($this->finished_at ?? now()) / 60);
    }
}
