<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A business expense: company overhead, or a cost of one job when it is linked to it (then it counts in that job's
 * Costs and profit, not in the overhead). Never on customer-facing documents.
 * Price and the tax paid are stored separately in the expense's original currency.
 */
class BusinessExpense extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['category_id', 'service_job_id', 'spent_on', 'description', 'merchant', 'amount', 'tax_amount', 'taxes', 'notes'];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d', 'amount' => 'integer', 'tax_amount' => 'integer', 'taxes' => 'array'];
    }

    public function total(): int
    {
        return $this->amount + $this->tax_amount;
    }

    /**
     * What it costs the company: the price plus the taxes that are not recovered.
     *
     * @param  list<int>  $recoverable  Ids of the tax rates the company recovers
     */
    public function cost(array $recoverable): int
    {
        return $this->amount + ($this->taxes === null
            // Older entries with one undivided tax amount count it in full.
            ? $this->tax_amount
            : (int) collect($this->taxes)->reject(fn (array $tax) => in_array((int) ($tax['tax_rate_id'] ?? 0), $recoverable, true))->sum('amount'));
    }

    /** @return BelongsTo<BusinessExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessExpenseCategory::class, 'category_id');
    }

    /** @return BelongsTo<ServiceJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @param Builder<self> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasRole(UserRole::Owner, UserRole::Admin)) {
            $query->where('created_by', $user->id);
        }
    }
}
