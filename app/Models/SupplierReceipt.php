<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A supplier receipt (photo or PDF), linked to one or more jobs. Strictly internal: never shown or sent to a
 * customer. Kept for years for the bookkeeper (not removed when a job is deleted).
 *
 * @property int $id
 * @property int $company_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string|null $supplier
 * @property Carbon|null $receipt_date
 * @property int|null $amount
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 */
class SupplierReceipt extends Model
{
    use BelongsToCompany;

    protected $fillable = ['path', 'original_name', 'mime', 'size', 'supplier', 'receipt_date', 'amount', 'uploaded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['receipt_date' => 'date:Y-m-d', 'amount' => 'integer', 'size' => 'integer'];
    }

    /**
     * @return BelongsToMany<ServiceJob, $this>
     */
    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(ServiceJob::class, 'supplier_receipt_links')
            ->using(SupplierReceiptLink::class)
            ->withPivot(['company_id', 'line_label'])
            ->withTimestamps()
            ->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
