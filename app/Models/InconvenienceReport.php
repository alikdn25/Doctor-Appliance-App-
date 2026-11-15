<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "Inconvenient": what a person wrote about the screen they were on, with that screen and their last 20 actions.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string|null $role
 * @property string|null $screen
 * @property string $text
 * @property list<array<string, mixed>> $recent_actions
 * @property Carbon $created_at
 */
class InconvenienceReport extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recent_actions' => 'array', 'created_at' => 'datetime'];
    }
}
