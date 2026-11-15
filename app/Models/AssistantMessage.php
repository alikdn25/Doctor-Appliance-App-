<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message of an assistant chat exactly as it was sent to the AI (the chat is append-only, so earlier messages
 * are never edited), with the text the chat shows for it.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $conversation
 * @property string $role
 * @property list<array<string, mixed>>|string $content
 * @property string|null $text
 * @property Carbon $created_at
 */
class AssistantMessage extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'conversation', 'role', 'content', 'text'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['content' => 'array', 'created_at' => 'datetime'];
    }
}
