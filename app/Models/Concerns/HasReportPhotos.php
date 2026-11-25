<?php

namespace App\Models\Concerns;

use App\Models\ReportPhoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A record with a report's photos (invoice, estimate, job report). The model names itself with REPORT_TYPE.
 *
 * @mixin Model
 */
trait HasReportPhotos
{
    /**
     * @return HasMany<ReportPhoto, $this>
     */
    public function reportPhotos(): HasMany
    {
        return $this->hasMany(ReportPhoto::class, 'reportable_id')
            ->where('reportable_type', static::REPORT_TYPE)
            ->orderBy('position')->orderBy('id');
    }
}
