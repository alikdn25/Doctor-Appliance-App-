<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasReportPhotos;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A separate service report of a job (owner's task of November 25): location, customer, address, appliance, date,
 * technician, description, photos and an optional customer signature. Filled from the job, every field can be
 * changed. A job can have several; each is kept under the job's Files and can be sent again.
 *
 * @property int $id
 * @property int $company_id
 * @property int $service_job_id
 * @property int|null $brand_address_id
 * @property int|null $user_id
 * @property string|null $customer_name
 * @property string|null $address
 * @property string|null $appliance_type
 * @property string|null $appliance_brand
 * @property string|null $appliance_model
 * @property string|null $appliance_serial
 * @property Carbon|null $report_date
 * @property string|null $technician_name
 * @property string|null $description
 * @property string|null $signature_path
 * @property string|null $signer_name
 * @property string $public_token
 * @property Carbon|null $sent_at
 * @property string|null $sent_to
 * @property Carbon $created_at
 * @property-read ServiceJob|null $job
 * @property-read BrandAddress|null $location
 */
class JobReport extends Model
{
    use BelongsToCompany, HasReportPhotos, RecordsChanges, SoftDeletes;

    public const AUDIT_TYPE = 'report';

    public const REPORT_TYPE = 'job_report';

    /** @var list<string> */
    protected array $auditIgnore = ['public_token'];

    protected $fillable = [
        'brand_address_id', 'customer_name', 'address', 'appliance_type', 'appliance_brand', 'appliance_model',
        'appliance_serial', 'report_date', 'technician_name', 'description', 'signer_name',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServiceJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id')->withTrashed();
    }

    /**
     * @return BelongsTo<BrandAddress, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(BrandAddress::class, 'brand_address_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
