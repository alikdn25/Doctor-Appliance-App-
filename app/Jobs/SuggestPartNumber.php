<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Models\Company;
use App\Models\ServiceJob;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\PartFinder;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job moved to "Need to order parts": the AI's possible part number is stored as a suggestion (marked "check").
 */
class SuggestPartNumber implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $jobId, public int $companyId) {}

    public function handle(CurrentCompany $tenancy, AiService $ai, PartFinder $finder): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null || ! $ai->configured()) {
            return;
        }

        $tenancy->runAs($company, function () use ($finder) {
            $job = ServiceJob::query()->find($this->jobId);

            if ($job === null || $job->status !== JobStatus::NeedToOrderParts || filled($job->part_number)) {
                return;
            }

            try {
                $suggestion = $finder->suggest($job);
            } catch (AiUnavailable) {
                return;
            }

            if ($suggestion !== null) {
                $job->forceFill(['part_suggestion' => $suggestion])->saveQuietly();
            }
        });
    }
}
