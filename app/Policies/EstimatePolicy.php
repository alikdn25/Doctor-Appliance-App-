<?php

namespace App\Policies;

use App\Actions\Billing\ReviseEstimate;
use App\Enums\OfficePermission;
use App\Models\Estimate;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

/**
 * Estimates follow their job: the people assigned to the job (technicians create estimates on site) and the
 * office of the job's brand with the Estimates permission. Anyone who sees the job can read them.
 */
class EstimatePolicy
{
    use AccessesJobs;

    public function view(User $user, Estimate $estimate): bool
    {
        return $this->inCurrentCompany($estimate) && $this->seesJob($user, $estimate->job);
    }

    /**
     * Lines and status can change until it is invoiced, and not after the customer signed it online
     * (the signature is for those lines and that total).
     */
    public function update(User $user, Estimate $estimate): bool
    {
        return $this->edits($user, $estimate) && $estimate->status->isOpen() && ! $estimate->approvedOnline();
    }

    /** Sending it to the customer (email or SMS). */
    public function send(User $user, Estimate $estimate): bool
    {
        return $this->edits($user, $estimate);
    }

    /**
     * Turning it into an invoice (approved online or not).
     */
    public function convert(User $user, Estimate $estimate): bool
    {
        return $this->edits($user, $estimate) && $estimate->status->isOpen() && $this->worksOn($user, $estimate)
            && $user->can('invoice', $estimate->job);
    }

    /**
     * Not once a deposit was paid on it.
     */
    public function delete(User $user, Estimate $estimate): bool
    {
        return $this->update($user, $estimate) && $estimate->depositPayments()->doesntExist();
    }

    /**
     * A new version of an estimate the customer signed online.
     */
    public function revise(User $user, Estimate $estimate): bool
    {
        return $this->edits($user, $estimate) && ReviseEstimate::canBeRevised($estimate) && $this->worksOn($user, $estimate);
    }

    private function edits(User $user, Estimate $estimate): bool
    {
        return $this->inCurrentCompany($estimate) && $this->billsJob($user, $estimate->job, OfficePermission::Estimates);
    }

    private function worksOn(User $user, Estimate $estimate): bool
    {
        return $user->can('work', $estimate->job);
    }
}
