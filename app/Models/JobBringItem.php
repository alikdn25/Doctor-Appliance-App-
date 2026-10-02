<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A part or material to bring to a return visit ("Bring with you"), ticked by the technician when loaded.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int $position
 * @property string $description
 * @property string $quantity
 * @property bool $is_checked
 * @property int|null $checked_by
 * @property Carbon|null $checked_at
 * @property-read User|null $checker
 */
class JobBringItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['position', 'description', 'quantity'];

    protected $attributes = ['is_checked' => false, 'quantity' => '1'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:2',
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
