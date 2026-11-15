<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One suggestion of an App review with the Owner's status: Done, Not needed or Later (empty = not decided).
 *
 * @property int $id
 * @property int $company_id
 * @property int $app_review_id
 * @property int $position
 * @property string $importance
 * @property string $title
 * @property string $why
 * @property string $fix
 * @property string|null $status
 * @property Carbon|null $status_at
 */
class AppReviewSuggestion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    public const STATUSES = ['done', 'not_needed', 'later'];

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<AppReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(AppReview::class, 'app_review_id');
    }
}
