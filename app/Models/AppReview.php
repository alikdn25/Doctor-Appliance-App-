<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One App review the Owner asked for: the period, the summary built by code, the AI's answer and what it cost.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string $period
 * @property array<string, mixed> $summary
 * @property array<string, mixed> $report
 * @property string $cost_usd
 * @property Carbon $created_at
 */
class AppReview extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period_from' => 'datetime', 'period_to' => 'datetime', 'summary' => 'array', 'report' => 'array', 'cost_usd' => 'decimal:6', 'created_at' => 'datetime'];
    }

    /**
     * @return HasMany<AppReviewSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(AppReviewSuggestion::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
