<?php

namespace App\Policies;

use App\Enums\OfficePermission;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

/**
 * Invoices follow their job: the people assigned to the job (technicians invoice and take payment on site) and the
 * office of the job's brand with the Invoices permission. Voiding and refunds are for that office.
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

    /** Starting a new invoice from the invoice list. */
    public function create(User $user): bool
    {
        return $this->isOffice($user) && $user->canOffice(OfficePermission::Invoices);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->inCurrentCompany($invoice) && $this->seesJob($user, $invoice->job);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->inCurrentCompany($invoice) && $this->billsJob($user, $invoice->job, OfficePermission::Invoices) && ! $invoice->isVoid();
    }

    /** Sending it to the customer (email or SMS). */
    public function send(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice) && $invoice->balance > 0;
    }

    /**
     * Giving money back (as settled): the office.
     */
    public function refund(User $user, Invoice $invoice): bool
    {
        return $this->inCurrentCompany($invoice) && $this->managesJob($user, $invoice->job, OfficePermission::Invoices) && ! $invoice->isVoid() && $invoice->amount_paid > 0;
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->inCurrentCompany($invoice) && $this->managesJob($user, $invoice->job, OfficePermission::Invoices) && ! $invoice->isVoid();
    }
}
