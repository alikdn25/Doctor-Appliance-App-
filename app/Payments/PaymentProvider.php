<?php

namespace App\Payments;

use App\Models\Company;
use App\Models\Invoice;

/**
 * An online payment provider a company can connect (SPEC §7.6): Square first, later Stripe, Moneris,
 * Helcim, Clover. Invoice logic never depends on a specific provider: a provider creates a way to pay
 * and reports payments back through App\Actions\Billing\RecordPayment::fromProvider().
 *
 * Implementations are registered in config/payments.php under their key().
 */
interface PaymentProvider
{
    /**
     * Stable key stored on the company and on payments, e.g. "square".
     */
    public function key(): string;

    /**
     * Name shown in settings, e.g. "Square".
     */
    public function label(): string;

    /**
     * Whether the company has finished connecting its own account (e.g. OAuth done).
     */
    public function isConnected(Company $company): bool;

    /**
     * A link where the customer pays the given amount (cents) of the invoice online or on the
     * technician's screen (QR code).
     */
    public function createPaymentLink(Invoice $invoice, int $amount): PaymentLink;
}
