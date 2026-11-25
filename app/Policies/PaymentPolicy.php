<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\AccessesJobs;

class PaymentPolicy
{
    use AccessesJobs;

    /**
     * The office voids a payment entered by mistake. Online payments are refunded at the provider.
     */
    public function void(User $user, Payment $payment): bool
    {
        return $this->inCurrentCompany($payment)
            && ! $payment->isVoid()
            && $payment->provider === null
            && $this->managesJob($user, $payment->invoice->job);
    }

    /** Any saved payment can be corrected (owner's task of November 25); provider data stays as it came. */
    public function edit(User $user, Payment $payment): bool
    {
        return $this->inCurrentCompany($payment)
            && $payment->invoice !== null
            && $user->can('view', $payment->invoice);
    }
}
