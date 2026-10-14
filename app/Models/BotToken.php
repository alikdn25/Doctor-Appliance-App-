<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An API token of the website booking bot (role "Bot"), one or more per company. Only the SHA-256 of the token is
 * stored; the token itself is shown once when it is created.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id The bot's own user (created_by of its jobs, the change log's "who")
 * @property string $name
 * @property string $token_hash
 * @property string $token_hint Last characters, to tell tokens apart
 * @property int $requests_per_minute
 * @property int $bookings_per_hour
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property-read Company $company
 * @property-read User $user
 */
class BotToken extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'requests_per_minute', 'bookings_per_hour'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requests_per_minute' => 'integer',
            'bookings_per_hour' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Creates a token with its own bot user; returns the model and the plain token (shown once).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Company $company, string $name, ?User $by): array
    {
        $plain = 'bot_'.Str::random(48);

        $user = new User;
        $user->forceFill([
            'name' => __('bot.user_name', ['name' => $name]),
            'email' => 'bot-'.Str::uuid().'@bot.invalid',
            'password' => Str::random(64),
            'is_bot' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $token = new self(['name' => $name]);
        $token->forceFill([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'token_hint' => substr($plain, -4),
            'created_by' => $by?->id,
        ])->save();

        return [$token, $plain];
    }

    /** The active token for a plain value, across companies (the token tells which company it is). */
    public static function findActive(string $plain): ?self
    {
        return static::withoutCompanyScope()
            ->where('token_hash', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
