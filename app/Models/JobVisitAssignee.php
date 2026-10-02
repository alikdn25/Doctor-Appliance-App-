<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A team member (technician, or an Owner/Admin who goes on calls) assigned to a visit.
 *
 * @property int $id
 * @property int $company_id
 * @property int $job_visit_id
 * @property int $user_id
 */
class JobVisitAssignee extends Pivot
{
    use BelongsToCompany;

    protected $table = 'job_visit_user';

    public $incrementing = true;
}
