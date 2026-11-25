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
 * @property PaymentTerms $default_payment_terms
 * @property string $invoice_prefix
 * @property int $invoice_next_number
 * @property string $estimate_prefix
 * @property int $estimate_next_number
 * @property int $job_next_number
 * @property int $travel_buffer_minutes
 * @property int|null $estimate_valid_days Prefills "Valid until" on new estimates
 * @property bool $technicians_can_delete_jobs
 * @property bool $works_alone One person does every job: no technician choice, every visit goes to the Owner
 * @property array<string, list<string>>|null $closure_reasons Reasons per job outcome; null/missing = defaults
 * @property int|null $diagnostic_service_id Price book service for "Invoice diagnosis only"
 * @property int $strict_arrival_reminder_minutes
 * @property int $warranty_labor_value
 * @property string $warranty_labor_unit
 * @property int $warranty_parts_value
 * @property string $warranty_parts_unit
 * @property int|null $warranty_parts_threshold Minor units
 * @property int|null $warranty_parts_above_value
 * @property string|null $warranty_parts_above_unit
 * @property string|null $warranty_terms
 * @property list<array{up_to: float|null, multiplier: float}>|null $markup_parts
 * @property list<array{up_to: float|null, multiplier: float}>|null $markup_materials
 * @property bool $technicians_see_costs
 * @property bool $accepts_cash
 * @property string|null $payment_provider
 * @property bool $timezone_pending
 * @property array<string, array{closed: bool, open: string|null, close: string|null}>|null $business_hours
 * @property list<array{start: string, end: string}>|null $arrival_windows
 * @property bool $tax_reserve_enabled
 * @property string $tax_reserve_rate Income and dividend tax, %
 * @property bool $tax_reserve_deduct_expense_tax
 * @property string $tax_reserve_basis paid or issued
 * @property int|null $tax_reserve_reminder_day
 * @property string|null $tax_reserve_reminded_month
 * @property bool $auto_reply_enabled Auto reply to incoming texts (Auto messages)
 * @property string|null $auto_reply_text
 * @property int $auto_reply_delay_minutes
 * @property bool $missed_call_reply_enabled Text back after a missed call to the company number (Telnyx voice)
 * @property string|null $missed_call_reply_text
 * @property int $sms_reminder_hours Reminder about an unanswered text every N hours (max 3)
 * @property int $warranty_lookback_days "Possible warranty" when the customer had a job finished within this many days
 * @property int $silence_reminder_hours "Customer is quiet": one reminder this many hours after our text got no answer
 * @property string|null $review_image_path Picture of the Google review block in invoice emails (220×220 PNG)
 * @property bool $morning_sms_enabled The morning SMS to the owner's own phone
 * @property string|null $morning_sms_phone E.164
 * @property string $morning_sms_time HH:MM, company time
 * @property Carbon|null $morning_sms_sent_on
 * @property string|null $google_drive_token Encrypted refresh token (Google Drive backup)
 * @property string|null $google_drive_email
 * @property string|null $google_drive_folder_id
 * @property Carbon|null $drive_backup_ran_on
 * @property string|null $payment_reminder_template Soft payment reminder ({first_name}, {my_name}, {company}, {appliance}, {month})
 * @property bool $translate_to_english Russian in the reply box becomes English before sending
 * @property int|null $diagnostic_fee Minor units of the company currency (Prices and answers)
 * @property string|null $service_areas
 * @property string|null $hours_text
 * @property string|null $answer_notes
 * @property bool $auto_clarify Send clarifying questions automatically
 * @property list<array{name: string, phone: string}>|null $outside_technicians
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
        'arrival_windows',
        'travel_buffer_minutes',
        'estimate_valid_days',
        'estimate_followup_days',
        'parts_stale_days',
        'service_area_south',
        'service_area_north',
        'service_area_west',
        'service_area_east',
        'estimate_stale_days',
        'technicians_can_delete_jobs',
        'works_alone',
        'closure_reasons',
        'diagnostic_service_id',
        'strict_arrival_reminder_minutes',
        'warranty_labor_value',
        'warranty_labor_unit',
        'warranty_parts_value',
        'warranty_parts_unit',
        'warranty_parts_threshold',
        'warranty_parts_above_value',
        'warranty_parts_above_unit',
        'warranty_terms',
        'markup_parts',
        'markup_materials',
        'technicians_see_costs',
        'accepts_cash',
        'timezone_pending',
        'payment_provider',
        'online_tips',
        'sms_mode',
        'quiet_hours_start',
        'quiet_hours_end',
        'message_templates',
        'booking_confirmation',
        'default_dictation_language',
        'distance_unit',
        'mileage_rate',
        'tax_reserve_enabled',
        'tax_reserve_rate',
        'tax_reserve_deduct_expense_tax',
        'tax_reserve_basis',
        'tax_reserve_reminder_day',
        'auto_reply_enabled',
        'auto_reply_text',
        'auto_reply_delay_minutes',
        'missed_call_reply_enabled',
        'missed_call_reply_text',
        'sms_reminder_hours',
        'warranty_lookback_days',
        'silence_reminder_hours',
        'morning_sms_enabled',
        'morning_sms_phone',
        'morning_sms_time',
        'payment_reminder_template',
        'translate_to_english',
        'diagnostic_fee',
        'service_areas',
        'hours_text',
        'answer_notes',
        'auto_clarify',
        'outside_technicians',
    ];

    protected $hidden = ['google_drive_token'];

    protected $attributes = [
        'status' => 'active',
        'warranty_labor_value' => 30,
        'warranty_labor_unit' => 'days',
        'warranty_parts_value' => 90,
        'warranty_parts_unit' => 'days',
        'technicians_see_costs' => false,
        'technicians_can_delete_jobs' => false,
        'works_alone' => false,
        'accepts_cash' => true,
        'strict_arrival_reminder_minutes' => 60,
        'vertical' => 'appliance_repair',
        'prices_include_tax' => false,
        'default_payment_terms' => 'due_on_receipt',
        'sms_mode' => 'technician_phone',
        'quiet_hours_start' => '21:00',
        'quiet_hours_end' => '08:00',
        'message_templates' => '{}',
        'auto_reply_enabled' => false,
        'auto_reply_delay_minutes' => 15,
        'missed_call_reply_enabled' => false,
        'sms_reminder_hours' => 2,
        'warranty_lookback_days' => 90,
        'silence_reminder_hours' => 2,
        'morning_sms_enabled' => false,
        'morning_sms_time' => '06:30',
        'translate_to_english' => true,
        'auto_clarify' => false,
    ];

    protected static function booted(): void
    {
        // Country defaults (SPEC §1.1) for whatever was not given explicitly.
        static::creating(function (Company $company) {
            $company->country = strtoupper($company->country ?: (string) config('fieldservice.default_country'));
            $company->currency ??= Countries::currency($company->country);
            $company->locale ??= Countries::locale($company->country);
            $company->timezone ??= Countries::timezone($company->country);
            // Road distances are in miles in the USA, the UK, Liberia and Myanmar; kilometres elsewhere.
            $company->distance_unit ??= in_array($company->country, ['US', 'GB', 'LR', 'MM'], true) ? 'mi' : 'km';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'mileage_rate' => 'integer',
            'service_area_south' => 'float',
            'service_area_north' => 'float',
            'service_area_west' => 'float',
            'service_area_east' => 'float',
            'subscription_status' => SubscriptionStatus::class,
            'vertical' => Vertical::class,
            'default_payment_terms' => PaymentTerms::class,
            'prices_include_tax' => 'boolean',
            'online_tips' => 'boolean',
            'sms_mode' => SmsMode::class,
            'message_templates' => 'array',
            'booking_confirmation' => 'array',
            'business_hours' => 'array',
            'arrival_windows' => 'array',
            'invoice_next_number' => 'integer',
            'estimate_next_number' => 'integer',
            'job_next_number' => 'integer',
            'travel_buffer_minutes' => 'integer',
            'estimate_valid_days' => 'integer',
            'estimate_followup_days' => 'integer',
            'parts_stale_days' => 'integer',
            'estimate_stale_days' => 'integer',
            'technicians_can_delete_jobs' => 'boolean',
            'works_alone' => 'boolean',
            'closure_reasons' => 'array',
            'strict_arrival_reminder_minutes' => 'integer',
            'warranty_labor_value' => 'integer',
            'warranty_parts_value' => 'integer',
            'warranty_parts_threshold' => 'integer',
            'warranty_parts_above_value' => 'integer',
            'markup_parts' => 'array',
            'markup_materials' => 'array',
            'technicians_see_costs' => 'boolean',
            'accepts_cash' => 'boolean',
            'timezone_pending' => 'boolean',
            'tax_reserve_enabled' => 'boolean',
            'tax_reserve_rate' => 'decimal:2',
            'tax_reserve_deduct_expense_tax' => 'boolean',
            'tax_reserve_reminder_day' => 'integer',
            'auto_reply_enabled' => 'boolean',
            'auto_reply_delay_minutes' => 'integer',
            'missed_call_reply_enabled' => 'boolean',
            'sms_reminder_hours' => 'integer',
            'warranty_lookback_days' => 'integer',
            'silence_reminder_hours' => 'integer',
            'morning_sms_enabled' => 'boolean',
            'morning_sms_sent_on' => 'date',
            'google_drive_token' => 'encrypted',
            'drive_backup_ran_on' => 'date',
            'translate_to_english' => 'boolean',
            'diagnostic_fee' => 'integer',
            'auto_clarify' => 'boolean',
            'outside_technicians' => 'array',
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
    /**
     * The box address suggestions are limited to (Company → Service area), or null for anywhere in the country.
     *
     * @return array{south: float, north: float, west: float, east: float}|null
     */
    public function serviceArea(): ?array
    {
        if ($this->service_area_south === null || $this->service_area_north === null
            || $this->service_area_west === null || $this->service_area_east === null) {
            return null;
        }

        return [
            'south' => (float) $this->service_area_south,
            'north' => (float) $this->service_area_north,
            'west' => (float) $this->service_area_west,
            'east' => (float) $this->service_area_east,
        ];
    }

    /**
     * The installation's default service area ("south,north,west,east" in GOOGLE_PLACES_DEFAULT_SERVICE_AREA),
     * or null when none is set or it is malformed.
     *
     * @return array{south: float, north: float, west: float, east: float}|null
     */
    public static function defaultServiceArea(): ?array
    {
        $parts = array_map('trim', explode(',', (string) config('services.google_maps.default_service_area')));

        if (count($parts) !== 4 || array_filter($parts, fn ($p) => ! is_numeric($p)) !== []) {
            return null;
        }

        [$south, $north, $west, $east] = array_map('floatval', $parts);

        if ($south < -90 || $north > 90 || $south >= $north || $west < -180 || $east > 180 || $west >= $east) {
            return null;
        }

        return compact('south', 'north', 'west', 'east');
    }

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
            'works_alone' => (bool) $this->works_alone,
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

    /**
     * The person every visit goes to in "work alone" mode: the active Owner (the first one if there are several).
     */
    public function soloWorkerId(): ?int
    {
        if (! $this->works_alone) {
            return null;
        }

        $id = Membership::withoutCompanyScope()
            ->where('company_id', $this->id)
            ->where('is_active', true)
            ->orderByRaw("case when role = 'owner' then 0 else 1 end")
            ->orderBy('id')
            ->value('user_id');

        return $id === null ? null : (int) $id;
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
    /**
     * The arrival windows offered when booking (UX-REDESIGN §25): the company's own list, or 9–11, 11–1, 1–3, 3–5.
     * Sorted by start; windows may overlap.
     *
     * @return list<array{start: string, end: string}>
     */
    public function arrivalWindows(): array
    {
        $own = is_array($this->arrival_windows) ? $this->arrival_windows : [];
        $windows = $own !== [] ? $own : self::defaultArrivalWindows();
        usort($windows, fn (array $a, array $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        return array_values($windows);
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    public static function defaultArrivalWindows(): array
    {
        return [
            ['start' => '09:00', 'end' => '11:00'],
            ['start' => '11:00', 'end' => '13:00'],
            ['start' => '13:00', 'end' => '15:00'],
            ['start' => '15:00', 'end' => '17:00'],
        ];
    }

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
