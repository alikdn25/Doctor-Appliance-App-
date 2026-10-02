<?php

namespace App\Payments;

/**
 * A provider's checkout link for an invoice amount.
 *
 * $orderReference is what the provider later puts on the payment (Square: the order ID),
 * used to match a webhook back to the invoice.
 */
final readonly class PaymentLink
{
    public function __construct(
        public string $url,
        public string $providerReference,
        public ?string $orderReference = null,
    ) {}
}
