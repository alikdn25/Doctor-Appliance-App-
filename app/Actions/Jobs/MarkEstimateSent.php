<?php

namespace App\Actions\Jobs;

use App\Enums\JobStatus;
use App\Models\ServiceJob;
use App\Models\User;

/**
 * Once the estimate reaches the customer, a job that was waiting for us to send it
 * now waits for the customer's answer. Must run in a tenant context.
 */
class MarkEstimateSent
{
    public function __construct(private readonly ChangeJobStatus $changeStatus) {}

    public function handle(?ServiceJob $job, User $user): void
    {
        if ($job !== null && $job->status === JobStatus::EstimateToSend) {
            $this->changeStatus->handle($job, JobStatus::WaitingForCustomer, $user);
        }
    }
}
