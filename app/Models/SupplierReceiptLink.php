<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A supplier receipt linked to a job (one receipt can cover parts for several jobs).
 *
 * @property int $id
 * @property int $company_id
 * @property int $supplier_receipt_id
 * @property int $service_job_id
 * @property string|null $line_label
 */
class SupplierReceiptLink extends Pivot
{
    use BelongsToCompany;

    protected $table = 'supplier_receipt_links';

    public $incrementing = true;

    protected $fillable = ['supplier_receipt_id', 'service_job_id', 'line_label'];
}
