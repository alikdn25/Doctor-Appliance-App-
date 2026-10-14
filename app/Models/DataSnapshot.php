<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily counts of a company's customers, jobs and invoices, taken by the watchdog (ops:watchdog) to notice a sudden
 * drop. Written by platform code for every company, so it is not tenant-scoped; always filter by company_id.
 *
 * @property int $id
 * @property int $company_id
 * @property Carbon $taken_on
 * @property int $customers
 * @property int $jobs
 * @property int $invoices
 */
class DataSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'customers' => 'integer',
            'jobs' => 'integer',
            'invoices' => 'integer',
        ];
    }
}
