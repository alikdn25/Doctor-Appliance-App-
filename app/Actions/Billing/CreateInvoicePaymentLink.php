<?php

namespace App\Actions\Billing;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoicePaymentLink;
use App\Models\User;
use App\Payments\PaymentProvider;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
use Illuminate\Support\Facades\DB;

/**
 * A payment link for the balance of an invoice through the company's online provider (SPEC §7.6), or for the
 * deposit still due on an estimate the customer approved online.
 * The current link is reused while the amount is unchanged; after a partial or manual payment a new
 * link for the new amount replaces it. Must run in a tenant context.
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

        return $this->link($invoice, $invoice->balance, $provider, $user);
    }

    /**
     * The deposit still due on an approved estimate (started by the customer from the online page).
     */
    public function deposit(Estimate $estimate): InvoicePaymentLink
    {
        $provider = $this->providers->readyFor(currentCompany());

        if ($provider === null) {
            throw new PaymentProviderException(__('payments.links.no_provider'));
        }

        if ($estimate->status !== EstimateStatus::Approved || $estimate->depositDue() <= 0) {
            throw new PaymentProviderException(__('payments.links.nothing_due'));
        }

        return $this->link($estimate, $estimate->depositDue(), $provider, null);
    }

    private function link(Estimate|Invoice $document, int $amount, PaymentProvider $provider, ?User $user): InvoicePaymentLink
    {
        $company = currentCompany();
        $current = $this->current($document, $provider->key());

        if ($current !== null && $current->amount === $amount && $current->currency === $document->currency) {
            return $current;
        }

        $link = $provider->createPaymentLink($document, $amount);

        return DB::transaction(function () use ($document, $amount, $provider, $link, $current, $user, $company) {
            if ($current !== null) {
                $current->update(['status' => InvoicePaymentLink::REPLACED]);
                $provider->cancelPaymentLink($company, $current->provider_link_id);
            }

            $record = new InvoicePaymentLink([
                'provider' => $provider->key(),
                'provider_link_id' => $link->providerReference,
                'provider_order_id' => $link->orderReference,
                'url' => $link->url,
                'amount' => $amount,
                'currency' => $document->currency,
                'created_by' => $user?->id,
            ]);
            $record->invoice_id = $document instanceof Invoice ? $document->id : null;
            $record->estimate_id = $document instanceof Estimate ? $document->id : null;
            $record->save();

            return $record;
        });
    }

    public function current(Estimate|Invoice $document, string $provider): ?InvoicePaymentLink
    {
        return InvoicePaymentLink::query()
            ->where($document instanceof Invoice ? 'invoice_id' : 'estimate_id', $document->id)
            ->where('provider', $provider)
            ->where('status', InvoicePaymentLink::ACTIVE)
            ->latest('id')
            ->first();
    }
}
