<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One "Find problems" run asked for by the Owner: what the code found and what the AI made of it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string|null $conversation
 * @property array<string, mixed> $findings
 * @property array<string, mixed> $report
 * @property Carbon $created_at
 */
class ProblemReport extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'conversation', 'after_message_id', 'findings', 'report'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['findings' => 'array', 'report' => 'array', 'created_at' => 'datetime'];
    }
}
