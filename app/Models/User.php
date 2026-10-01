<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Users are global (not tenant-owned). Access to companies goes through
 * memberships (company_user), which carry the role.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property bool $is_active
 * @property int|null $current_company_id
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    protected $attributes = [
        'is_super_admin' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Emails are unique platform-wide and compared case-insensitively.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * All memberships of this user across companies. A user's own memberships
     * are always visible to them, so the tenant scope is lifted here.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * Companies this user can currently work in (active membership, active company).
     *
     * @return Collection<int, Company>
     */
    public function accessibleCompanies(): Collection
    {
        return $this->companies()
            ->wherePivot('is_active', true)
            ->where('companies.status', CompanyStatus::Active->value)
            ->orderBy('companies.name')
            ->get();
    }

    /**
     * Brands this user is limited to in the current company. Empty means all brands.
     *
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)->withTimestamps();
    }

    public function membershipFor(Company|int|null $company): ?Membership
    {
        if ($company === null) {
            return null;
        }

        $companyId = $company instanceof Company ? $company->id : $company;

        return $this->memberships()->where('company_id', $companyId)->first();
    }

    /**
     * The membership for the company of the current request (cached per request).
     */
    public function currentMembership(): ?Membership
    {
        $companyId = app(CurrentCompany::class)->id();

        if ($companyId === null) {
            return null;
        }

        $cached = $this->relationLoaded('currentMembership') ? $this->getRelation('currentMembership') : null;

        if ($cached instanceof Membership && $cached->company_id === $companyId) {
            return $cached;
        }

        $membership = $this->memberships()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        $this->setRelation('currentMembership', $membership);

        return $membership;
    }

    public function currentRole(): ?UserRole
    {
        return $this->currentMembership()?->role;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        $role = $this->currentRole();

        return $role !== null && in_array($role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeSuperAdmins(Builder $query): void
    {
        $query->where('is_super_admin', true);
    }
}
