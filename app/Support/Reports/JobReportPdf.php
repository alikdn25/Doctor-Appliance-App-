<?php

namespace App\Support\Reports;

use App\Models\Brand;
use App\Models\JobReport;
use App\Support\Billing\DocumentPdf;
use App\Support\Billing\DocumentPrint;
use App\Support\Locale\AddressFormatter;
use App\Support\PrivateMedia;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The PDF of a job report (owner's task of November 25), in the style of the invoices: the logo and the chosen
 * location (address, phone, tax number), the customer, address, appliance, date, technician, description, photos two
 * in a row with captions and the customer's signature when there is one. Kept under 8 MB. Tenant context.
 */
class JobReportPdf
{
    public static function render(JobReport $report): string
    {
        $report->loadMissing(['job.brand.addresses', 'location.brand.addresses']);
        $company = currentCompany();
        $brand = $report->location?->brand ?? $report->job?->brand ?? Brand::query()->with('addresses')->orderBy('id')->first();
        $header = DocumentPrint::brand($brand, $company->country, embedLogo: true) + ['fallback_name' => $company->name];

        // The chosen location's address replaces the brand's main one.
        if ($report->location !== null) {
            $location = $report->location;
            $header['address'] = AddressFormatter::oneLine(
                AddressFormatter::street($location->line1, $location->line2, $location->city, $location->country),
                $location->city, $location->region, AddressFormatter::postal($location->postal_code), $location->country,
            );
        }

        $signature = null;
        if ($report->signature_path !== null && PrivateMedia::disk()->exists($report->signature_path)) {
            $signature = 'data:image/png;base64,'.base64_encode((string) PrivateMedia::disk()->get($report->signature_path));
        }

        $steps = $report->reportPhotos()->exists() ? DocumentPdf::PHOTO_STEPS : [[0, 0]];
        $pdf = '';
        foreach ($steps as [$side, $quality]) {
            $pdf = DocumentPdf::paper(Pdf::loadView('pdf.job-report', [
                'brand' => $header,
                'report' => $report,
                'date' => DocumentPrint::date($report->report_date),
                'appliance' => trim(implode(' · ', array_filter([$report->appliance_type, $report->appliance_brand, $report->appliance_model]))),
                'photos' => ['text' => null, 'photos' => $side > 0 ? ServiceReport::forPdf($report, $side, $quality) : []],
                'signature' => $signature,
                'number' => $report->job?->number,
            ]))->output();

            if (strlen($pdf) <= DocumentPdf::MAX_BYTES) {
                break;
            }
        }

        return $pdf;
    }

    public static function filename(JobReport $report): string
    {
        return 'Report-'.($report->job?->number ?? 'job').'-'.$report->id.'.pdf';
    }
}
