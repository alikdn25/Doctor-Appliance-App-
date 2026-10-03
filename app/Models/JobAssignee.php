<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class JobAssignee extends Pivot
{
    use BelongsToCompany;

    protected $table = 'service_job_user';

    public $incrementing = true;
}
