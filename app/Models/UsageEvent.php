<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One action of a person in the app, for App review: a screen opened, time on it, a button tapped, a form started /
 * saved / abandoned, "back", a search found / not found, an error seen, a main flow finished. Only actions, screens,
 * the role and time — never customer data. Kept 90 days (usage:prune).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string|null $role
 * @property string|null $session
 * @property string $type
 * @property string|null $screen
 * @property string|null $name
 * @property int|null $value
 * @property array<string, mixed>|null $meta
 * @property Carbon $occurred_at
 */
class UsageEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    public const TYPES = ['screen_view', 'screen_time', 'tap', 'form_start', 'form_save', 'form_abandon', 'back', 'search', 'error', 'flow', 'flow_abandoned'];

    public const KEEP_DAYS = 90;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }
}
