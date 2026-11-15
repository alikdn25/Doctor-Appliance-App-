<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import from Square: read the customers (scanning), the Owner reviews the matches (review), then customers and
 * the invoice history are written in the background (importing → done). Undo sends everything it added to Deleted
 * items (undone).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $status
 * @property int $progress_done
 * @property int $progress_total
 * @property string|null $step
 * @property array<string, int>|null $counts
 * @property array<string, mixed>|null $merged
 * @property string|null $error
 * @property Carbon|null $finished_at
 * @property Carbon|null $undone_at
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class SquareImport extends Model
{
    use BelongsToCompany;

    public const SCANNING = 'scanning';

    public const REVIEW = 'review';

    public const IMPORTING = 'importing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const UNDONE = 'undone';

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'merged' => 'array',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return HasMany<SquareImportCustomer, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(SquareImportCustomer::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::SCANNING, self::IMPORTING], true);
    }
}
