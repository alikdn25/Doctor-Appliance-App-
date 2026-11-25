<?php

namespace App\Support\Billing;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Reports\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Branded PDF of an estimate or invoice (dompdf, no external requests). Must run in a tenant context.
 *
 * With a report (owner's task of November 25) the "Service report" follows the lines and totals: its text, then the
 * photos two in a row with captions, over as many pages as it needs. The file is kept under 8 MB: when it is bigger
 * the photos are made smaller and the PDF is made again.
 */
class DocumentPdf
{
    /** Countries that use US Letter paper; everyone else gets A4. */
    public const LETTER_COUNTRIES = ['US', 'CA', 'MX', 'PH', 'CL', 'CO', 'VE', 'CR', 'GT', 'PR', 'PA', 'DO', 'SV', 'NI', 'HN', 'BZ'];

    /** The largest PDF sent to a customer. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Photo size and JPEG quality tried in turn until the PDF fits. */
    public const PHOTO_STEPS = [[1200, 72], [900, 60], [700, 50], [500, 40], [360, 35]];

    public static function render(Estimate|Invoice $document): string
    {
        $hasReport = $document->has_report;
        $steps = $hasReport && $document->reportPhotos()->exists() ? self::PHOTO_STEPS : [[0, 0]];
        $pdf = '';

        foreach ($steps as [$side, $quality]) {
            $report = $hasReport ? [
                'text' => $document->report_text,
                'photos' => $side > 0 ? ServiceReport::forPdf($document, $side, $quality) : [],
            ] : null;

            $pdf = self::paper(Pdf::loadView('pdf.document', [
                'doc' => DocumentPrint::data($document, embedLogo: true),
                'url' => PublicDocument::url($document),
                'report' => $report,
            ]))->output();

            if (strlen($pdf) <= self::MAX_BYTES) {
                break;
            }
        }

        return $pdf;
    }

    /** Letter or A4 by the company's country; no remote files. */
    public static function paper(\Barryvdh\DomPDF\PDF $pdf): \Barryvdh\DomPDF\PDF
    {
        return $pdf
            ->setPaper(in_array(currentCompany()->country, self::LETTER_COUNTRIES, true) ? 'letter' : 'a4')
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    }

    public static function filename(Estimate|Invoice $document): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $document->number).'.pdf';
    }
}
