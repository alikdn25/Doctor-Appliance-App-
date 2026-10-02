<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Scopes\CompanyScope;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The tenant. Relations below lift the tenant scope because they are already
 * constrained to this company; they are used by platform (super-admin) code.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property CompanyStatus $status
 * @property string|null $plan
 * @property SubscriptionStatus|null $subscription_status
 * @property string $timezone
 * @property string $currency
 * @property string $invoice_prefix
 * @property int $invoice_next_number
 * @property string $estimate_prefix
 * @property int $estimate_next_number
 * @property int $job_next_number
 * @property int $travel_buffer_minutes
 * @property string|null $payment_provider
 * @property bool $timezone_pending
 * @property array<string, array{closed: bool, open: string|null, close: string|null}>|null $business_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'name',
        'slug',
        'status',
        'plan',
        'subscription_status',
        'timezone',
        'currency',
        'invoice_prefix',
        'invoice_next_number',
        'estimate_prefix',
        'estimate_next_number',
        'business_hours',
        'travel_buffer_minutes',
        'timezone_pending',
        'payment_provider',
    ];

    protected $attributes = [
        'status' => 'active',
        'timezone' => 'America/Vancouver',
        'currency' => 'CAD',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'subscription_status' => SubscriptionStatus::class,
            'business_hours' => 'array',
            'invoice_next_number' => 'integer',
            'estimate_next_number' => 'integer',
            'job_next_number' => 'integer',
            'travel_buffer_minutes' => 'integer',
            'timezone_pending' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return HasMany<TaxRate, $this>
     */
    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return HasMany<ImpersonationLog, $this>
     */
    public function impersonationLogs(): HasMany
    {
        return $this->hasMany(ImpersonationLog::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Default business hours: Mon–Fri 08:00–17:00, weekends closed.
     *
     * @return array<string, array{closed: bool, open: string|null, close: string|null}>
     */
    public static function defaultBusinessHours(): array
    {
        $hours = [];

        foreach (self::WEEKDAYS as $day) {
            $weekend = in_array($day, ['sat', 'sun'], true);
            $hours[$day] = [
                'closed' => $weekend,
                'open' => $weekend ? null : '08:00',
                'close' => $weekend ? null : '17:00',
            ];
        }

        return $hours;
    }
}
