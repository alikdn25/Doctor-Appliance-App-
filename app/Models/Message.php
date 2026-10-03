<?php

namespace App\Models;

use App\Enums\MessageKind;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message to or from a customer: an SMS (sent by the platform or received), an email, or an SMS opened on a
 * technician's phone. Shown on the customer and job timelines.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $customer_id
 * @property int|null $service_job_id
 * @property string $direction outbound or inbound
 * @property string $channel sms, email or technician_phone
 * @property MessageKind $kind
 * @property string|null $to
 * @property string|null $from
 * @property string $body
 * @property string $status scheduled, sending, sent, delivered, failed, blocked, received or opened
 * @property string|null $status_reason
 * @property string|null $provider_message_id
 * @property Carbon|null $send_after
 * @property Carbon|null $sent_at
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read Customer|null $customer
 * @property-read ServiceJob|null $job
 * @property-read User|null $user
 */
class Message extends Model
{
    use BelongsToCompany;

    public const OUTBOUND = 'outbound';

    public const INBOUND = 'inbound';

    public const SMS = 'sms';

    public const EMAIL = 'email';

    public const TECHNICIAN_PHONE = 'technician_phone';

    protected $fillable = [
        'customer_id', 'service_job_id', 'direction', 'channel', 'kind', 'to', 'from', 'body',
        'status', 'status_reason', 'provider_message_id', 'send_after', 'sent_at', 'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MessageKind::class,
            'send_after' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** Correspondence follows job visibility; unassigned conversations belong to the office. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $query) use ($user) {
            $query->whereIn('service_job_id', ServiceJob::query()->visibleTo($user)->select('id'));
            if ($user->hasRole(UserRole::Owner, UserRole::Admin)) {
                $query->orWhereNull('service_job_id');
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
