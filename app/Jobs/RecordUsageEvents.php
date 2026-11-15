<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\UsageEvent;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a batch of already cleaned usage events in the background, so the action log never slows the app down.
 */
class RecordUsageEvents implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(public int $companyId, public ?int $userId, public ?string $role, public ?string $session, public array $events) {}

    public function handle(CurrentCompany $tenancy): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($company) {
            UsageEvent::query()->insert(array_map(fn (array $e) => [
                ...$e,
                'meta' => $e['meta'] === null ? null : json_encode($e['meta']),
                'occurred_at' => $e['occurred_at'] instanceof \DateTimeInterface ? $e['occurred_at']->format('Y-m-d H:i:s') : $e['occurred_at'],
                'company_id' => $company->id,
                'user_id' => $this->userId,
                'role' => $this->role,
                'session' => $this->session,
            ], $this->events));
        });
    }
}
