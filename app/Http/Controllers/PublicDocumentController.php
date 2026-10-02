<?php

namespace App\Http\Controllers;

use App\Actions\Billing\CreateInvoicePaymentLink;
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
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The customer's online page of an estimate or invoice (no login; the long random token in the link is the key).
 * Shows the branded document, the PDF, and for an invoice with a balance a "Pay online" button.
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
