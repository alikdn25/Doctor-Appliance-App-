<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser or phone that receives push notifications for a user (Web Push). Platform-level: tied to the person,
 * not to a company.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string|null $user_agent
 */
class PushSubscription extends Model
{
    protected $fillable = ['endpoint', 'public_key', 'auth_token', 'user_agent'];

    protected $hidden = ['auth_token'];

    protected static function booted(): void
    {
        static::saving(fn (PushSubscription $subscription) => $subscription->endpoint_hash = hash('sha256', $subscription->endpoint));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
