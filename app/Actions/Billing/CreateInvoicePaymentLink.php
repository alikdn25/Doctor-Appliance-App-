<?php

namespace App\Actions\Billing;

use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\User;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
use Illuminate\Support\Facades\DB;

/**
 * A payment link for the balance of an invoice through the company's online provider (SPEC §7.6).
 * The current link is reused while the balance is unchanged; after a partial or manual payment a new
 * link for the new balance replaces it. Must run in a tenant context.
 */
class CreateInvoicePaymentLink
{
    public function __construct(private readonly PaymentProviders $providers) {}

    /**
     * $user is null when the customer starts the payment from the online invoice page.
     */
    public function handle(Invoice $invoice, ?User $user): InvoicePaymentLink
    {
        $company = currentCompany();
        $provider = $this->providers->readyFor($company);

        if ($provider === null) {
            throw new PaymentProviderException(__('payments.links.no_provider'));
        }

        if ($invoice->isVoid() || $invoice->balance <= 0) {
            throw new PaymentProviderException(__('payments.links.nothing_due'));
        }

        $current = $this->current($invoice, $provider->key());

        if ($current !== null && $current->amount === $invoice->balance && $current->currency === $invoice->currency) {
            return $current;
        }

        $link = $provider->createPaymentLink($invoice, $invoice->balance);

        return DB::transaction(function () use ($invoice, $provider, $link, $current, $user, $company) {
            if ($current !== null) {
                $current->update(['status' => InvoicePaymentLink::REPLACED]);
                $provider->cancelPaymentLink($company, $current->provider_link_id);
            }

            $record = new InvoicePaymentLink([
                'provider' => $provider->key(),
                'provider_link_id' => $link->providerReference,
                'provider_order_id' => $link->orderReference,
                'url' => $link->url,
                'amount' => $invoice->balance,
                'currency' => $invoice->currency,
                'created_by' => $user?->id,
            ]);
            $record->invoice_id = $invoice->id;
            $record->save();

            return $record;
        });
    }

    public function current(Invoice $invoice, string $provider): ?InvoicePaymentLink
    {
        return InvoicePaymentLink::query()
            ->where('invoice_id', $invoice->id)
            ->where('provider', $provider)
            ->where('status', InvoicePaymentLink::ACTIVE)
            ->latest('id')
            ->first();
    }
}
