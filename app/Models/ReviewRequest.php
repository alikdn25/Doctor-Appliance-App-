<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Google review request for a paid job (one per job). The same request goes to every customer: no incentives,
 * no "are you happy?" filter (Google and FTC rules, SPEC §8).
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property int $service_job_id
 * @property int|null $google_profile_id
 * @property string $status scheduled, sent or skipped
 * @property string|null $channel
 * @property string|null $skip_reason
 * @property Carbon|null $send_after
 * @property Carbon|null $sent_at
 * @property int|null $message_id
 * @property-read ServiceJob $job
 * @property-read GoogleProfile|null $profile
 */
class ReviewRequest extends Model
{
    use BelongsToCompany;

    public const SCHEDULED = 'scheduled';

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    protected $fillable = ['customer_id', 'service_job_id', 'google_profile_id', 'status', 'channel', 'skip_reason', 'send_after', 'sent_at', 'message_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['send_after' => 'datetime', 'sent_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id')->withTrashed();
    }

    /**
     * @return BelongsTo<GoogleProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(GoogleProfile::class, 'google_profile_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
