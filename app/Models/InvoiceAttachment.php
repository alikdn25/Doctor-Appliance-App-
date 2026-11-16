<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file attached to an invoice (a photo or screenshot it was made from). Private: streamed only after the invoice's
 * view check, never by a public URL.
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property int|null $user_id
 * @property string $path
 * @property string $mime_type
 * @property int|null $size
 * @property Carbon $created_at
 */
class InvoiceAttachment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['invoice_id', 'user_id', 'path', 'mime_type', 'size'];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
