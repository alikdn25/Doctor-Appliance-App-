{{-- Job report PDF (dompdf), in the style of the invoices (owner's task of November 25). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('service_reports.pdf_title') }}</title>
    <style>
        @page { margin: 32px 36px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.4; }
        .bar { height: 5px; background: {{ $brand['color'] }}; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .logo { max-height: 64px; max-width: 220px; margin-bottom: 6px; }
        .brand-name { font-size: 16px; font-weight: bold; }
        .muted { color: #6b7280; }
        .doc-title { font-size: 22px; font-weight: bold; text-align: right; color: {{ $brand['color'] }}; }
        .facts { margin-top: 18px; }
        .facts td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .facts td.label { width: 28%; color: #6b7280; text-transform: uppercase; font-size: 9px; }
        .report-title { font-size: 14px; font-weight: bold; margin-top: 22px; padding-bottom: 4px; border-bottom: 1.5px solid {{ $brand['color'] }}; }
        .report-text { white-space: pre-line; margin-top: 8px; }
        .report-photos { margin-top: 10px; table-layout: fixed; }
        .report-photos td { width: 50%; padding: 4px; vertical-align: top; text-align: center; page-break-inside: avoid; }
        .report-photos img { max-width: 100%; max-height: 300px; }
        .report-photos .caption { font-size: 9.5px; color: #4b5563; margin-top: 3px; }
        .signature { margin-top: 20px; page-break-inside: avoid; }
        .signature img { max-height: 70px; max-width: 260px; }
    </style>
</head>
<body>
    <div class="bar"></div>
    <table class="head">
        <tr>
            <td style="width: 60%;">
                @if ($brand['logo'])
                    <img class="logo" src="{{ $brand['logo'] }}" alt="">
                @endif
                <div class="brand-name">{{ $brand['name'] ?? $brand['fallback_name'] }}</div>
                @foreach (array_filter([$brand['address'], $brand['phone'], $brand['email'], $brand['website']]) as $line)
                    <div class="muted">{{ $line }}</div>
                @endforeach
                @if ($brand['tax_number'])
                    <div class="muted">{{ __('documents.tax_number', ['number' => $brand['tax_number']]) }}</div>
                @endif
            </td>
            <td style="width: 40%;">
                <div class="doc-title">{{ __('service_reports.pdf_title') }}</div>
                @if ($number)
                    <div class="muted" style="text-align: right;">#{{ $number }}</div>
                @endif
                @if ($date)
                    <div class="muted" style="text-align: right;">{{ $date }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="facts">
        @foreach ([
            'customer' => $report->customer_name,
            'address' => $report->address,
            'appliance' => $appliance,
            'serial' => $report->appliance_serial,
            'date' => $date,
            'technician' => $report->technician_name,
        ] as $key => $value)
            @if (filled($value))
                <tr><td class="label">{{ __('service_reports.'.$key) }}</td><td>{{ $value }}</td></tr>
            @endif
        @endforeach
    </table>

    @if (filled($report->description) || ! empty($photos['photos']))
        <div class="report-title">{{ __('service_reports.description') }}</div>
        @if (filled($report->description))
            <div class="report-text">{{ $report->description }}</div>
        @endif
        @include('pdf.partials.report', ['report' => $photos])
    @endif

    @if ($signature)
        <div class="signature">
            <img src="{{ $signature }}" alt="">
            @if ($report->signer_name)
                <div class="muted">{{ __('service_reports.signed_by', ['name' => $report->signer_name]) }}</div>
            @endif
        </div>
    @endif
</body>
</html>
