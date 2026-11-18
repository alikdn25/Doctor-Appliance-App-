<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A push notification with buttons (owner's task of November 19): what it says and what each button does. A tap on a
 * button comes back with the token; only the person it was sent to can use it, once, before it expires. "Later"
 * sends the same notification again an hour later (resend_at).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $token
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property string|null $url
 * @property array<string, array<string, mixed>> $actions Button key => {title, op, ...}
 * @property Carbon|null $resend_at
 * @property Carbon|null $used_at
 * @property Carbon $expires_at
 * @property-read User $user
 */
class PushAction extends Model
{
    use BelongsToCompany;

    protected $fillable = ['user_id', 'token', 'kind', 'title', 'body', 'url', 'actions', 'resend_at', 'used_at', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'resend_at' => 'datetime',
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
