<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo in a report (owner's task of November 25): one of the job's photos, with an optional caption, in the order
 * the person set. reportable_type is "invoice", "estimate" or "job_report".
 *
 * @property int $id
 * @property int $company_id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property int $job_photo_id
 * @property string|null $caption
 * @property int $position
 * @property-read JobPhoto|null $photo
 */
class ReportPhoto extends Model
{
    use BelongsToCompany;

    protected $fillable = ['reportable_type', 'reportable_id', 'job_photo_id', 'caption', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<JobPhoto, $this>
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(JobPhoto::class, 'job_photo_id');
    }
}
