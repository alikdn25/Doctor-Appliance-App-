<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google Business Profile of the company (e.g. per brand or city) with its direct review link (SPEC §8).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $brand_id
 * @property string $label
 * @property string $review_url
 */
class GoogleProfile extends Model
{
    use BelongsToCompany;

    protected $fillable = ['brand_id', 'label', 'review_url'];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withTrashed();
    }
}
