<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\RecordsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A personal to-do without a customer ("+ → Task"): a title, a day, a time or all day, a note, who it is for and an
 * optional reminder. Shown in the calendar and on My Jobs in its own colour.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $created_by
 * @property int $user_id
 * @property string $title
 * @property string|null $note
 * @property Carbon $task_date
 * @property bool $all_day
 * @property string|null $start_time
 * @property string|null $end_time
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $remind_minutes
 * @property Carbon|null $remind_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $completed_at
 * @property string|null $kind parts / materials / home / business / call
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $service_job_id
 * @property string $repeat none / weekly / monthly
 * @property Carbon|null $repeated_at The next one of the series has been made.
 * @property Carbon|null $planned_date The day it was first planned (carried over since).
 * @property int|null $supplier_id An automatic "Buy at {supplier}" task.
 * @property-read ServiceJob|null $job
 * @property-read User $user
 * @property-read User|null $creator
 */
class Task extends Model
{
    /** Change log type (owner's task of November 25: every correction is logged). */
    public const AUDIT_TYPE = 'task';

    use BelongsToCompany, RecordsChanges, SoftDeletes;

    /** Reminder choices, in minutes before the start. */
    public const REMINDERS = [0, 15, 60, 1440];

    /** An all-day task's reminder counts from this hour of its day. */
    public const ALL_DAY_START = '08:00';

    public const KIND_PARTS = 'parts';

    public const KIND_MATERIALS = 'materials';

    /** Seen only by the one who made it. */
    public const KIND_HOME = 'home';

    public const KINDS = [self::KIND_PARTS, self::KIND_MATERIALS, self::KIND_HOME, 'business', 'call'];

    public const REPEATS = ['none', 'weekly', 'monthly'];

    protected $fillable = [
        'user_id', 'title', 'note', 'task_date', 'all_day', 'start_time', 'end_time', 'remind_minutes',
        'kind', 'address', 'service_job_id', 'repeat',
    ];

    protected $attributes = ['repeat' => 'none'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task_date' => 'date:Y-m-d',
            'all_day' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'remind_minutes' => 'integer',
            'remind_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
            'repeated_at' => 'datetime',
            'planned_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        // The moments follow the day and time in the company's time zone; a changed time reminds again.
        static::saving(function (Task $task) {
            $timezone = currentCompany()->timezone;
            $day = $task->task_date->format('Y-m-d');
            $at = fn (string $time) => CarbonImmutable::createFromFormat('Y-m-d H:i', "{$day} {$time}", $timezone)->utc();

            if ($task->all_day) {
                $task->start_time = null;
                $task->end_time = null;
            }

            $task->starts_at = $task->all_day ? null : $at((string) $task->start_time);
            $task->ends_at = $task->all_day || $task->end_time === null ? null : $at($task->end_time);
            $task->remind_at = $task->remind_minutes === null
                ? null
                : $at($task->all_day ? self::ALL_DAY_START : (string) $task->start_time)->subMinutes($task->remind_minutes);

            if ($task->isDirty(['task_date', 'start_time', 'all_day', 'remind_minutes'])) {
                $task->reminded_at = null;
            }

            // A new address is placed on the map again (unless its position comes with it).
            if ($task->isDirty('address') && ! $task->isDirty('latitude')) {
                $task->latitude = null;
                $task->longitude = null;
            }
        });
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
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    /** Days since it was first planned (an undone task carried over). */
    public function carriedDays(): int
    {
        return $this->planned_date === null ? 0 : max(0, (int) $this->planned_date->diffInDays($this->task_date));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tasks the person may see: their own and the ones they gave; the office sees everyone's. A Home task is seen
     * only by the one who made it.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('viewAll', self::class)) {
            $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('created_by', $user->id));
        }

        $query->where(fn (Builder $q) => $q->whereNull('kind')->orWhere('kind', '!=', self::KIND_HOME)->orWhere('created_by', $user->id));
    }
}
