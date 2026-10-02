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
}
