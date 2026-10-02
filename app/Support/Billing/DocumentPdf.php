<?php

namespace App\Support\Billing;

use App\Models\Estimate;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Branded PDF of an estimate or invoice (dompdf, no external requests). Must run in a tenant context.
 */
class DocumentPdf
{
    /** Countries that use US Letter paper; everyone else gets A4. */
    private const LETTER_COUNTRIES = ['US', 'CA', 'MX', 'PH', 'CL', 'CO', 'VE', 'CR', 'GT', 'PR', 'PA', 'DO', 'SV', 'NI', 'HN', 'BZ'];

    public static function render(Estimate|Invoice $document): string
    {
        return Pdf::loadView('pdf.document', [
            'doc' => DocumentPrint::data($document, embedLogo: true),
            'url' => PublicDocument::url($document),
        ])
            ->setPaper(in_array(currentCompany()->country, self::LETTER_COUNTRIES, true) ? 'letter' : 'a4')
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }

    public static function filename(Estimate|Invoice $document): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->number).'.pdf';
    }
}
