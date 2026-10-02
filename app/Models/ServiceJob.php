<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PhoneNumber;
use Database\Factories\ServiceJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A job (work order). Stored in "service_jobs" because "jobs" is Laravel's queue table.
 *
 * @property int $id
 * @property int $company_id
 * @property int $number
 * @property int $brand_id
 * @property int $customer_id
 * @property int $property_id
 * @property JobType $job_type
 * @property LeadSource|null $lead_source
 * @property JobStatus $status
 * @property string|null $description
 * @property string|null $notes
 * @property string|null $tech_notes
 * @property string|null $signature_path
 * @property string|null $signature_name
 * @property Carbon|null $signed_at
 * @property int|null $signed_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Brand $brand
 * @property-read Customer $customer
 * @property-read Property $property
 */
class ServiceJob extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ServiceJobFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'brand_id',
        'property_id',
        'job_type',
        'lead_source',
        'description',
        'notes',
        'tech_notes',
        'ask_for_review',
    ];

    protected $attributes = [
        'status' => 'new',
        'job_type' => 'repair',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'job_type' => JobType::class,
            'lead_source' => LeadSource::class,
            'status' => JobStatus::class,
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'signed_at' => 'datetime',
            'ask_for_review' => 'boolean',
        ];
    }

    public function displayNumber(): string
    {
        return "#{$this->number}";
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<Appliance, $this, JobAppliance>
     */
    public function appliances(): BelongsToMany
    {
        return $this->belongsToMany(Appliance::class, 'job_appliance')
            ->using(JobAppliance::class)
            ->withTimestamps()
            ->withTrashed()
            ->orderBy('appliances.type')
            ->orderBy('appliances.id');
    }

    /**
     * @return HasMany<JobVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(JobVisit::class)->orderBy('scheduled_start')->orderBy('id');
    }

    /**
     * @return HasMany<JobStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(JobStatusChange::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return HasMany<JobPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(JobPhoto::class)->orderBy('taken_at')->orderBy('id');
    }

    /**
     * @return HasMany<JobChecklistItem, $this>
     */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(JobChecklistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Estimate, $this>
     */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class)->orderBy('id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /**
     * Replaces the checklist with the company's template for the job type
     * (only while nothing has been ticked, so finished work is never lost).
     */
    public function applyChecklistTemplate(): void
    {
        if ($this->checklistItems()->where('is_done', true)->exists()) {
            return;
        }

        $this->checklistItems()->delete();
        $items = ChecklistTemplate::query()->where('job_type', $this->job_type->value)->first()?->items ?? [];

        foreach (array_values($items) as $i => $label) {
            $this->checklistItems()->create(['position' => $i, 'label' => $label]);
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isAssigned(User $user): bool
    {
        return $this->visits()
            ->whereHas('assignees', fn (Builder $q) => $q->where('users.id', $user->id))
            ->exists();
    }

    /**
     * Jobs the user may see: jobs they are assigned to (any visit), plus every job of their
     * brands for the office (Owner, Admin). An office user limited to some brands sees only those.
     *
     * @param  Builder<ServiceJob>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $office = $user->hasRole(UserRole::Owner, UserRole::Admin);
        $brandIds = $office ? $user->limitedBrandIds() : [];

        $query->where(function (Builder $q) use ($user, $office, $brandIds) {
            $q->whereHas('visits.assignees', fn (Builder $a) => $a->where('users.id', $user->id));

            if ($office) {
                $q->orWhere(fn (Builder $b) => $brandIds === []
                    ? $b->whereNotNull('service_jobs.id')
                    : $b->whereIn('service_jobs.brand_id', $brandIds));
            }
        });
    }

    /**
     * Search by number, customer name, phone (any format), address, model or serial number.
     *
     * @param  Builder<ServiceJob>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $number = ltrim($term, '#');
        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = PhoneNumber::searchDigits($term);

        $query->where(function (Builder $q) use ($number, $like, $digits) {
            if (ctype_digit($number) && strlen($number) <= 9) {
                $q->orWhere('service_jobs.number', (int) $number);
            }

            $q->orWhereHas('customer', fn (Builder $c) => $c->where(fn (Builder $n) => $n
                ->where('display_name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)))
                ->orWhereHas('property', fn (Builder $p) => $p->where(fn (Builder $a) => $a
                    ->where('line1', 'ilike', $like)
                    ->orWhere('unit', 'ilike', $like)
                    ->orWhere('city', 'ilike', $like)
                    ->orWhere('postal_code', 'ilike', $like)))
                ->orWhereHas('appliances', fn (Builder $a) => $a->where(fn (Builder $m) => $m
                    ->where('model_number', 'ilike', $like)
                    ->orWhere('serial_number', 'ilike', $like)));

            if (strlen($digits) >= 3) {
                $q->orWhereHas('customer.phones', fn (Builder $p) => $p->where('number_normalized', 'like', "%{$digits}%"));
            }
        });
    }
}
