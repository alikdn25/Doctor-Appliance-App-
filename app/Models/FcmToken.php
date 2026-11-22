<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The Android app on one phone receiving push notifications for a user (Firebase Cloud Messaging token). One row per
 * install: a phone signed in by another person moves to that person. Platform-level, not tied to a company.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $token_hash
 * @property string $platform
 * @property Carbon|null $last_seen_at
 */
class FcmToken extends Model
{
    protected $fillable = ['token', 'platform', 'last_seen_at'];

    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::saving(fn (FcmToken $token) => $token->token_hash = hash('sha256', $token->token));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
