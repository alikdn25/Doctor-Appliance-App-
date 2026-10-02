<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int $position
 * @property string $label
 * @property bool $is_done
 * @property int|null $done_by
 * @property Carbon|null $done_at
 * @property-read User|null $doneBy
 */
class JobChecklistItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['position', 'label', 'is_done', 'done_by', 'done_at'];

    protected $attributes = ['is_done' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_done' => 'boolean',
            'done_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
