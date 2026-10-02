<?php

namespace App\Payments;

/**
 * A provider's checkout link for an invoice amount.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string $url,
        public string $providerReference,
    ) {}
}
