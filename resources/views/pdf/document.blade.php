{{-- Estimate / invoice PDF (dompdf). All values come preformatted from App\Support\Billing\DocumentPrint. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $doc['title'] }} {{ $doc['number'] }}</title>
    <style>
        @page { margin: 32px 36px 48px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; line-height: 1.4; }
        .accent { color: {{ $doc['brand']['color'] }}; }
        .bar { height: 5px; background: {{ $doc['brand']['color'] }}; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .logo { max-height: 64px; max-width: 220px; margin-bottom: 6px; }
        .brand-name { font-size: 16px; font-weight: bold; }
        .muted { color: #6b7280; }
        .doc-title { font-size: 22px; font-weight: bold; text-align: right; }
        .meta td { padding: 1px 0; }
        .meta td.label { color: #6b7280; padding-right: 10px; text-align: right; }
        .section { margin-top: 18px; }
        .items th { text-align: left; font-size: 9.5px; text-transform: uppercase; color: #6b7280; border-bottom: 1.5px solid {{ $doc['brand']['color'] }}; padding: 6px 4px; }
        .items td { padding: 7px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .num, .items th.num { text-align: right; white-space: nowrap; }
        .totals { width: 46%; margin-left: 54%; margin-top: 10px; }
        .totals td { padding: 3px 4px; }
        .totals .grand td { font-size: 13px; font-weight: bold; border-top: 1.5px solid #1f2937; padding-top: 6px; }
        .box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 10px; margin-top: 14px; white-space: pre-line; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; text-align: center; font-size: 9px; color: #6b7280; }
        .pay { margin-top: 14px; padding: 8px 10px; border: 1.5px solid {{ $doc['brand']['color'] }}; }
        .pay a { color: {{ $doc['brand']['color'] }}; }
        .excluded td { color: #9ca3af; }
        .tag { font-size: 8.5px; text-transform: uppercase; color: #6b7280; }
        .approval { margin-top: 16px; padding: 10px 12px; border: 1.5px solid #16a34a; }
        .approval .title { font-weight: bold; color: #15803d; }
        .signature { max-height: 70px; max-width: 260px; margin: 6px 0 2px; }
        .typed-signature { font-size: 20px; font-style: italic; margin: 6px 0 2px; }
    </style>
</head>
<body>
    <div class="bar"></div>

    <table class="head">
        <tr>
            <td style="width: 55%;">
                @if ($doc['brand']['logo'])
                    <img class="logo" src="{{ $doc['brand']['logo'] }}" alt="">
                @endif
                <div class="brand-name">{{ $doc['brand']['name'] ?? $doc['brand']['fallback_name'] }}</div>
                @foreach (array_filter([$doc['brand']['address'], $doc['brand']['phone'], $doc['brand']['email'], $doc['brand']['website']]) as $line)
                    <div class="muted">{{ $line }}</div>
                @endforeach
                @if ($doc['brand']['tax_number'])
                    <div class="muted">{{ __('documents.tax_number', ['number' => $doc['brand']['tax_number']]) }}</div>
                @endif
                @if ($doc['brand']['business_number'])
                    <div class="muted">{{ __('documents.business_number', ['number' => $doc['brand']['business_number']]) }}</div>
                @endif
            </td>
            <td style="width: 45%;">
                <div class="doc-title accent">{{ $doc['title'] }}</div>
                <table class="meta" style="width: auto; margin-left: auto;">
                    <tr><td class="label">{{ __('documents.number') }}</td><td>{{ $doc['number'] }}</td></tr>
                    <tr><td class="label">{{ __('documents.date') }}</td><td>{{ $doc['issued_on'] }}</td></tr>
                    @if ($doc['due_on'])
                        <tr><td class="label">{{ __('documents.due') }}</td><td>{{ $doc['due_on'] }}</td></tr>
                    @endif
                    @if ($doc['valid_until'])
                        <tr><td class="label">{{ __('documents.valid_until') }}</td><td>{{ $doc['valid_until'] }}</td></tr>
                    @endif
                    @if ($doc['job_number'])
                        <tr><td class="label">{{ __('documents.job') }}</td><td>#{{ $doc['job_number'] }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="section">
        <div class="muted" style="text-transform: uppercase; font-size: 9px;">{{ __('documents.bill_to') }}</div>
        <div style="font-weight: bold;">{{ $doc['customer']['name'] }}</div>
        @foreach (array_filter([$doc['customer']['address'], $doc['customer']['phone'], $doc['customer']['email']]) as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>

    <table class="items section">
        <thead>
            <tr>
                <th>{{ __('billing.fields.description') }}</th>
                <th class="num">{{ __('billing.fields.quantity') }}</th>
                <th class="num">{{ __('billing.fields.unit_price') }}</th>
                <th class="num">{{ __('billing.fields.line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $item)
                <tr @class(['excluded' => ! $item['included']])>
                    <td style="white-space: pre-line;">@if ($item['optional'])<span class="tag">{{ $item['included'] ? __('estimates.optional_included') : __('estimates.optional_not_included') }}</span><br>@endif{{ $item['description'] }}@unless ($item['taxable'])<span class="muted"> · {{ __('billing.not_taxable') }}</span>@endunless</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ $item['unit_price'] }}</td>
                    <td class="num">{{ $item['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{{ __('billing.subtotal') }}</td><td class="num">{{ $doc['subtotal'] }}</td></tr>
        @if ($doc['discount'])
            <tr><td>{{ __('billing.discount') }}</td><td class="num">{{ $doc['discount'] }}</td></tr>
        @endif
        @foreach ($doc['taxes'] as $tax)
            <tr><td>{{ $tax['label'] }}</td><td class="num">{{ $tax['amount'] }}</td></tr>
        @endforeach
        <tr class="grand"><td>{{ __('billing.total') }}</td><td class="num">{{ $doc['total'] }}</td></tr>
        @if ($doc['prices_include_tax'])
            <tr><td colspan="2" class="muted" style="text-align: right;">{{ __('billing.prices_include_tax') }}</td></tr>
        @endif
        @if ($doc['amount_paid'])
            <tr><td>{{ __('invoices.paid') }}</td><td class="num">{{ $doc['amount_paid'] }}</td></tr>
        @endif
        @if ($doc['balance'] !== null)
            <tr class="grand"><td>{{ __('invoices.balance') }}</td><td class="num">{{ $doc['balance'] }}</td></tr>
        @endif
    </table>

    @if (! empty($url) && $doc['kind'] === 'invoice' && $doc['balance_minor'] > 0)
        <div class="pay">{{ __('documents.pay_online_pdf') }} <a href="{{ $url }}">{{ $url }}</a></div>
    @endif

    @if ($doc['approval'] && $doc['approval']['deposit'])
        <table class="totals" style="margin-top: 4px;">
            <tr><td>{{ $doc['approval']['deposit_percent'] ? __('estimates.deposit_percent', ['percent' => $doc['approval']['deposit_percent']]) : __('estimates.deposit') }}</td><td class="num">{{ $doc['approval']['deposit'] }}</td></tr>
            @if ($doc['approval']['deposit_paid'])
                <tr><td>{{ __('estimates.deposit_paid') }}</td><td class="num">{{ $doc['approval']['deposit_paid'] }}</td></tr>
            @endif
        </table>
    @endif

    @if ($doc['approval'] && $doc['approval']['online'])
        <div class="approval">
            <div class="title">{{ __('estimates.online.approved_title') }}</div>
            @if ($doc['approval']['signature'])
                <img class="signature" src="{{ $doc['approval']['signature'] }}" alt="">
            @else
                <div class="typed-signature">{{ $doc['approval']['signer_name'] }}</div>
            @endif
            <div>{{ __('estimates.online.signed_by', ['name' => $doc['approval']['signer_name'], 'date' => $doc['approval']['approved_at']]) }}</div>
            @if ($doc['approval']['approved_ip'])
                <div class="muted">{{ __('estimates.online.ip', ['ip' => $doc['approval']['approved_ip']]) }}</div>
            @endif
        </div>
    @endif

    @if ($doc['notes'])
        <div class="box">{{ $doc['notes'] }}</div>
    @endif

    @if ($doc['terms'])
        <div class="section">
            <div style="font-weight: bold;">{{ __('documents.terms') }}</div>
            <div style="white-space: pre-line;" class="muted">{{ $doc['terms'] }}</div>
        </div>
    @endif

    @if ($doc['footer'])
        <div class="footer">{{ $doc['footer'] }}</div>
    @endif
</body>
</html>
