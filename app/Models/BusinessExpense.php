<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company overhead, independent of jobs, invoices and customer-facing documents.
 * Price and the tax paid are stored separately in the expense's original currency.
 */
class BusinessExpense extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = ['category_id', 'spent_on', 'description', 'merchant', 'amount', 'tax_amount', 'notes'];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d', 'amount' => 'integer', 'tax_amount' => 'integer'];
    }

    public function total(): int
    {
        return $this->amount + $this->tax_amount;
    }

    /** @return BelongsTo<BusinessExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessExpenseCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<self> $query */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->hasRole(UserRole::Owner, UserRole::Admin)) {
            $query->where('created_by', $user->id);
        }
    }
}
