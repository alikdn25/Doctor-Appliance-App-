<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\JobOutcome;
use App\Enums\PaymentTerms;
use App\Enums\SmsMode;
use App\Enums\SubscriptionStatus;
use App\Enums\Vertical;
use App\Models\Scopes\CompanyScope;
use App\Support\Locale\Countries;
use App\Support\Locale\Currencies;
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
 * @property string $country ISO 3166-1 alpha-2
 * @property Vertical $vertical
 * @property string $timezone
 * @property string $currency ISO 4217
 * @property string $locale Regional format (BCP 47), e.g. en-US
 * @property bool $prices_include_tax
 * @property bool $online_tips Customers may add a tip when paying online
 * @property SmsMode $sms_mode
 * @property string $quiet_hours_start HH:MM, company time
 * @property string $quiet_hours_end HH:MM, company time
 * @property array<string, string> $message_templates Overrides of the default texts by MessageKind value
 * @property bool $review_requests_default
 * @property int $review_request_delay_hours
 * @property int $review_request_cooldown_days
 * @property PaymentTerms $default_payment_terms
 * @property string $invoice_prefix
 * @property int $invoice_next_number
 * @property string $estimate_prefix
 * @property int $estimate_next_number
 * @property int $job_next_number
 * @property int $travel_buffer_minutes
 * @property int|null $estimate_valid_days Prefills "Valid until" on new estimates
 * @property bool $technicians_can_delete_jobs
 * @property array<string, list<string>>|null $closure_reasons Reasons per job outcome; null/missing = defaults
 * @property int|null $diagnostic_service_id Price book service for "Invoice diagnosis only"
 * @property int $strict_arrival_reminder_minutes
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
        'country',
        'vertical',
        'timezone',
        'currency',
        'locale',
        'prices_include_tax',
        'default_payment_terms',
        'invoice_prefix',
        'invoice_next_number',
        'estimate_prefix',
        'estimate_next_number',
        'business_hours',
        'travel_buffer_minutes',
        'estimate_valid_days',
        'technicians_can_delete_jobs',
        'closure_reasons',
        'diagnostic_service_id',
        'strict_arrival_reminder_minutes',
        'timezone_pending',
        'payment_provider',
        'online_tips',
        'sms_mode',
        'quiet_hours_start',
        'quiet_hours_end',
        'message_templates',
        'review_requests_default',
        'review_request_delay_hours',
        'review_request_cooldown_days',
    ];

    protected $attributes = [
        'status' => 'active',
        'vertical' => 'appliance_repair',
        'prices_include_tax' => false,
        'default_payment_terms' => 'due_on_receipt',
        'sms_mode' => 'technician_phone',
        'quiet_hours_start' => '21:00',
        'quiet_hours_end' => '08:00',
        'message_templates' => '{}',
        'review_requests_default' => true,
        'review_request_delay_hours' => 2,
        'review_request_cooldown_days' => 180,
    ];

    protected static function booted(): void
    {
        // Country defaults (SPEC §1.1) for whatever was not given explicitly.
        static::creating(function (Company $company) {
            $company->country = strtoupper($company->country ?: (string) config('fieldservice.default_country'));
            $company->currency ??= Countries::currency($company->country);
            $company->locale ??= Countries::locale($company->country);
            $company->timezone ??= Countries::timezone($company->country);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'subscription_status' => SubscriptionStatus::class,
            'vertical' => Vertical::class,
            'default_payment_terms' => PaymentTerms::class,
            'prices_include_tax' => 'boolean',
            'online_tips' => 'boolean',
            'sms_mode' => SmsMode::class,
            'message_templates' => 'array',
            'review_requests_default' => 'boolean',
            'review_request_delay_hours' => 'integer',
            'review_request_cooldown_days' => 'integer',
            'business_hours' => 'array',
            'invoice_next_number' => 'integer',
            'estimate_next_number' => 'integer',
            'job_next_number' => 'integer',
            'travel_buffer_minutes' => 'integer',
            'estimate_valid_days' => 'integer',
            'technicians_can_delete_jobs' => 'boolean',
            'closure_reasons' => 'array',
            'strict_arrival_reminder_minutes' => 'integer',
            'timezone_pending' => 'boolean',
        ];
    }

    /**
     * Digits after the decimal point of the company's currency.
     */
    public function currencyDecimals(): int
    {
        return Currencies::decimals($this->currency);
    }

    /**
     * Formats and labels the React pages need: regional format, currency, address layout.
     *
     * @return array<string, mixed>
     */
    public function formatSettings(): array
    {
        return [
            'country' => $this->country,
            'currency' => $this->currency,
            'currency_decimals' => $this->currencyDecimals(),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'vertical' => $this->vertical->value,
            'tracks_appliances' => $this->vertical->tracksAppliances(),
            'prices_include_tax' => $this->prices_include_tax,
            'sms_mode' => $this->sms_mode->value,
            'address' => Countries::addressLabels($this->country),
        ];
    }

    /**
     * Reasons to pick from when a job ends with this outcome (the company's list, or the defaults).
     *
     * @return list<string>
     */
    public function closureReasons(JobOutcome $outcome): array
    {
        $own = $this->closure_reasons[$outcome->value] ?? null;

        return is_array($own) && $own !== [] ? array_values($own) : $outcome->defaultReasons();
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
