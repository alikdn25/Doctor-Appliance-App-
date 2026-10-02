<?php

namespace App\Models;

use App\Enums\PhotoKind;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A before/after photo taken on a job. Files are served through an authorized route, never by a public URL.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int|null $job_visit_id
 * @property int|null $user_id
 * @property PhotoKind $kind
 * @property string $path
 * @property int|null $size
 * @property string $client_uuid
 * @property Carbon|null $taken_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class JobPhoto extends Model
{
    use BelongsToCompany;

    protected $fillable = ['kind', 'job_visit_id', 'client_uuid', 'taken_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PhotoKind::class,
            'size' => 'integer',
            'taken_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (JobPhoto $photo) {
            Storage::disk(config('fieldservice.media_disk'))->delete($photo->path);
        });
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
