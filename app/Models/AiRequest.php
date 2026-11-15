<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One request to the AI (written by App\Services\AI\AiService): who asked, for what, which model, tokens, cost in
 * USD and how long it took. The daily limit per company is counted from these rows.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $feature
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost_usd
 * @property int $duration_ms
 * @property string $status
 * @property string|null $error
 * @property Carbon $created_at
 */
class AiRequest extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'created_at' => 'datetime',
        ];
    }
}
