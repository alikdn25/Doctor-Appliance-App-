<?php

namespace App\Http\Controllers;

use App\Actions\Billing\CreateInvoicePaymentLink;
use App\Actions\Billing\DecideEstimateOnline;
use App\Enums\EstimateStatus;
use App\Models\Company;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Payments\PaymentProviderException;
use App\Payments\PaymentProviders;
use App\Support\Billing\DocumentPdf;
use App\Support\Billing\DocumentPrint;
use App\Support\Billing\PublicDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The customer's online page of an estimate or invoice (no login; the long random token in the link is the key).
 * Shows the branded document, the PDF, and for an invoice with a balance a "Pay online" button.
 * An estimate can be approved (with a signature, picking optional lines, then paying a deposit if one is asked)
 * or declined (SPEC §7.5).
 */
class PublicDocumentController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function show(string $token, PaymentProviders $providers): Response
    {
        [$document, $company] = $this->resolve($token);

        return $this->tenancy->runAs($company, function () use ($document, $company, $providers) {
            if ($document->viewed_at === null && auth()->guest()) {
                $document->forceFill(['viewed_at' => now()])->saveQuietly();
            }

            $canPay = $document instanceof Invoice
                && ! $document->isVoid()
                && $document->balance > 0
                && $providers->readyFor($company) !== null;

            return Inertia::render('public/document', [
                'token' => $document->public_token,
                'document' => DocumentPrint::data($document),
                'canPay' => $canPay,
                'locale' => $company->locale,
                'estimate' => $document instanceof Estimate ? $this->estimateActions($document, $company, $providers) : null,
            ]);
        });
    }

    public function pdf(string $token): HttpResponse
    {
        [$document, $company] = $this->resolve($token);

        return $this->tenancy->runAs($company, fn () => response(DocumentPdf::render($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.DocumentPdf::filename($document).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]));
    }

    /**
     * Sends the customer to the provider's checkout for the current balance.
     */
    public function pay(string $token, CreateInvoicePaymentLink $links): SymfonyResponse|RedirectResponse
    {
        [$document, $company] = $this->resolve($token);
        abort_unless($document instanceof Invoice, 404);

        return $this->tenancy->runAs($company, function () use ($document, $links, $token) {
            try {
                $link = $links->handle($document, null);
            } catch (PaymentProviderException $e) {
                return redirect()->route('documents.public', $token)->withErrors(['pay' => $e->getMessage()]);
            }

            return Inertia::location($link->url);
        });
    }

    public function approve(Request $request, string $token, DecideEstimateOnline $decide, CreateInvoicePaymentLink $links): SymfonyResponse|RedirectResponse
    {
        [$estimate, $company] = $this->resolve($token);
        abort_unless($estimate instanceof Estimate, 404);

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:100'],
            'signature_type' => ['required', Rule::in([Estimate::SIGNATURE_DRAWN, Estimate::SIGNATURE_TYPED])],
            // PNG as a data URL (base64 is a third larger than the file).
            'signature' => ['nullable', 'required_if:signature_type,'.Estimate::SIGNATURE_DRAWN, 'string', 'max:'.(int) ceil(DecideEstimateOnline::MAX_SIGNATURE_BYTES * 4 / 3 + 64)],
            'selected_items' => ['array', 'max:100'],
            'selected_items.*' => ['integer'],
        ], attributes: [
            'signer_name' => __('estimates.online.signer_name'),
            'signature' => __('estimates.online.signature'),
        ]);

        $png = null;
        if ($data['signature_type'] === Estimate::SIGNATURE_DRAWN) {
            $encoded = preg_replace('#^data:image/png;base64,#', '', (string) $data['signature']);
            $png = base64_decode((string) $encoded, true);

            if ($png === false || $png === '') {
                throw ValidationException::withMessages(['signature' => __('estimates.online.errors.signature')]);
            }
        }

        return $this->tenancy->runAs($company, function () use ($estimate, $data, $png, $request, $decide, $links, $token, $company) {
            $estimate = $decide->approve(
                $estimate,
                $data['selected_items'] ?? [],
                $data['signer_name'],
                $png,
                $request->ip(),
                $request->userAgent(),
            );

            // A deposit is asked: straight to the provider's checkout when the company takes online payments.
            if ($estimate->depositDue() > 0 && app(PaymentProviders::class)->readyFor($company) !== null) {
                try {
                    return Inertia::location($links->deposit($estimate)->url);
                } catch (PaymentProviderException $e) {
                    return redirect()->route('documents.public', $token)->withErrors(['pay' => $e->getMessage()]);
                }
            }

            return redirect()->route('documents.public', $token);
        });
    }

    public function decline(Request $request, string $token, DecideEstimateOnline $decide): RedirectResponse
    {
        [$estimate, $company] = $this->resolve($token);
        abort_unless($estimate instanceof Estimate, 404);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        $this->tenancy->runAs($company, fn () => $decide->decline($estimate, $data['reason'] ?? null, $request->ip()));

        return redirect()->route('documents.public', $token);
    }

    /**
     * Sends the customer to the provider's checkout for the deposit still due on an approved estimate.
     */
    public function deposit(string $token, CreateInvoicePaymentLink $links): SymfonyResponse|RedirectResponse
    {
        [$estimate, $company] = $this->resolve($token);
        abort_unless($estimate instanceof Estimate, 404);

        return $this->tenancy->runAs($company, function () use ($estimate, $links, $token) {
            try {
                $link = $links->deposit($estimate);
            } catch (PaymentProviderException $e) {
                return redirect()->route('documents.public', $token)->withErrors(['pay' => $e->getMessage()]);
            }

            return Inertia::location($link->url);
        });
    }

    /**
     * What the customer can do with an estimate, and the numbers to recalculate the total live when they pick
     * optional lines (mirrors DocumentTotals in the browser; the server recalculates on approval).
     *
     * @return array<string, mixed>
     */
    private function estimateActions(Estimate $estimate, Company $company, PaymentProviders $providers): array
    {
        $estimate->loadMissing('items');
        $open = $estimate->awaitsCustomer();
        $online = $providers->readyFor($company) !== null;

        return [
            'online_payments' => $online,
            'can_approve' => $open && ! $estimate->isExpired(),
            'can_decline' => $open && $estimate->status === EstimateStatus::Draft,
            'can_pay_deposit' => $online && $estimate->status === EstimateStatus::Approved && $estimate->depositDue() > 0,
            'customer_name' => $estimate->customer?->display_name,
            'calc' => [
                'currency' => $estimate->currency,
                'locale' => $company->locale,
                'discount_type' => $estimate->discount_type,
                'discount_value' => (string) $estimate->discount_value,
                'prices_include_tax' => $estimate->prices_include_tax,
                'taxes' => collect($estimate->taxes)->map(fn (array $tax) => [
                    'name' => $tax['name'],
                    'rate' => (string) $tax['rate'],
                    'compound' => (bool) ($tax['compound'] ?? false),
                ])->values()->all(),
                'items' => $estimate->items->map(fn ($item) => [
                    'id' => $item->id,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => $item->unit_price,
                    'taxable' => $item->taxable,
                    'optional' => $item->optional,
                    'selected' => $item->selected,
                ])->values()->all(),
                'deposit_type' => $estimate->deposit_type,
                'deposit_value' => (string) $estimate->deposit_value,
            ],
        ];
    }

    /**
     * @return array{0: Estimate|Invoice, 1: Company}
     */
    private function resolve(string $token): array
    {
        $document = PublicDocument::find($token) ?? abort(404);
        $company = Company::query()->find($document->company_id);

        // Drafts of deleted documents or suspended companies are not shown.
        abort_if($company === null || ! $company->isActive() || $document->trashed(), 404);

        return [$document, $company];
    }
}
