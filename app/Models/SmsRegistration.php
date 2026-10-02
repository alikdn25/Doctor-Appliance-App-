<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * US A2P 10DLC registration of a company (business details for the carriers' brand and campaign registration).
 * Until it is approved no SMS goes to US numbers.
 *
 * @property int $id
 * @property int $company_id
 * @property string $status draft, submitted, approved or rejected
 * @property array<string, string|null> $business
 * @property string|null $brand_registration_sid
 * @property string|null $messaging_service_sid
 * @property string|null $campaign_sid
 * @property string|null $rejection_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 */
class SmsRegistration extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /**
     * Business details asked for the registration (all required to submit, except website and EIN-less sole props).
     */
    public const FIELDS = [
        'legal_name', 'business_type', 'ein', 'website',
        'street', 'city', 'region', 'postal_code',
        'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone',
        'use_case_description', 'sample_message',
    ];

    protected $fillable = [
        'status', 'business', 'brand_registration_sid', 'messaging_service_sid', 'campaign_sid',
        'rejection_reason', 'submitted_at', 'approved_at',
    ];

    protected $attributes = ['status' => self::DRAFT, 'business' => '{}'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
