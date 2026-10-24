<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A team note on a job (the Notes tab): who wrote it and when. Never shown to the customer.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class JobNote extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['body'];

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
