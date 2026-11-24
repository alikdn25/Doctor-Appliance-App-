<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Appliances worked on in a job. Gives every appliance its repair history.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int $appliance_id
 */
class JobAppliance extends Pivot
{
    use BelongsToCompany;

    protected $table = 'job_appliance';

    public $incrementing = true;

    protected static function booted(): void
    {
        // The job takes the appliance's type and brand for Statistics when it has none yet.
        static::created(function (JobAppliance $link) {
            $job = ServiceJob::withoutCompanyScope()->where('company_id', $link->company_id)->find($link->service_job_id);
            $appliance = Appliance::withoutCompanyScope()->withTrashed()->where('company_id', $link->company_id)->find($link->appliance_id);

            if ($job !== null && $appliance !== null) {
                $job->takeApplianceInfo($appliance);
            }
        });
    }
}
