<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\JobPart;
use App\Models\ServiceJob;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\PartFinder;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A part to order without a number: the AI's possible part number goes into the part, marked "check" until someone
 * corrects or confirms it (Parts page, job page).
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

            if ($job === null) {
                return;
            }

            foreach ($job->parts()->where('status', JobPart::TO_ORDER)->whereNull('part_number')->limit(5)->get() as $part) {
                try {
                    $suggestion = $finder->suggest($job, $part->name);
                } catch (AiUnavailable) {
                    return;
                }

                if ($suggestion !== null) {
                    JobPart::query()->whereKey($part->id)->whereNull('part_number')
                        ->update(['part_number' => $suggestion['part_number'], 'number_unverified' => true]);
                }
            }
        });
    }
}
