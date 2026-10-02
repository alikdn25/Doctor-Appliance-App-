<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership in a company. Carries the role.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property UserRole $role
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Company $company
 */
class Membership extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $table = 'company_user';

    protected $fillable = ['company_id', 'user_id', 'role', 'is_active'];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Active members who can be assigned to job visits (Owners and Admins go on calls too).
     *
     * @param  Builder<Membership>  $query
     */
    public function scopeAssignable(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value, UserRole::Technician->value]);
    }
}
