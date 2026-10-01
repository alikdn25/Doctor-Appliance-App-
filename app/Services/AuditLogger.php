<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Records sensitive actions (role changes, settings, impersonation, suspension).
 */
class AuditLogger
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, ?Model $subject = null, array $changes = [], ?int $companyId = null): AuditLog
    {
        $companyId ??= $this->currentCompany->id()
            ?? ($subject?->getAttribute('company_id') ?? ($subject instanceof Company ? $subject->id : null));

        return AuditLog::create([
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'impersonator_id' => $this->impersonation->impersonatorId(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
