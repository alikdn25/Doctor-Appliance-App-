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
}
