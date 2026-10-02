<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

/**
 * Invoices follow their job: the office of the job's brand and the people assigned to the job
 * (technicians invoice and take payment on site). Voiding is for the office.
 */
class InvoicePolicy
{
    use AccessesJobs;

    /**
     * The list of all invoices (office).
     */
    public function viewAny(User $user): bool
    {
        return $this->isOffice($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->inCurrentCompany($invoice) && $this->seesJob($user, $invoice->job);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice) && ! $invoice->isVoid();
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice) && $invoice->balance > 0;
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->inCurrentCompany($invoice) && $this->managesJob($user, $invoice->job) && ! $invoice->isVoid();
    }
}
