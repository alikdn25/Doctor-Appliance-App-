{{-- Receipt after payment (UX-REDESIGN §19): one column up to 600 px, tables and inline styles for Gmail, Outlook and Apple Mail. --}}
@php($brand = $print['brand'])
@php($color = $brand['color'])
@php($brandName = $brand['name'] ?? $brand['fallback_name'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ __('documents.receipt.subject', ['brand' => $brandName, 'number' => $print['number']]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;">
                {{-- Brand banner: the logo in the middle on the brand colour. --}}
                <tr>
                    <td align="center" bgcolor="{{ $color }}" style="background:{{ $color }};padding:28px 24px;">
                        @if ($brand['logo'])
                            <img src="{{ $brand['logo'] }}" alt="{{ $brandName }}" height="56" style="display:block;height:56px;max-width:240px;border:0;outline:none;text-decoration:none;background:#ffffff;border-radius:8px;padding:6px;">
                        @else
                            <span style="font-size:22px;font-weight:bold;color:#ffffff;">{{ $brandName }}</span>
                        @endif
                    </td>
                </tr>

                {{-- The amount, Paid, the date and the way it was paid. --}}
                <tr>
                    <td align="center" style="padding:28px 24px 8px;">
                        <div style="font-size:36px;line-height:42px;font-weight:bold;">{{ $paidAmount }}</div>
                        <div style="margin-top:6px;font-size:16px;font-weight:bold;color:#15803d;">&#10003; {{ __('documents.receipt.paid') }}</div>
                        <div style="margin-top:8px;font-size:14px;color:#475569;">
                            {{ collect([$paidOn, $method, $reference ? __('documents.receipt.reference', ['reference' => $reference]) : null])->filter()->implode(' · ') }}
                        </div>
                        <div style="margin-top:4px;font-size:13px;color:#64748b;">{{ __('documents.receipt.invoice', ['number' => $print['number']]) }}</div>
                    </td>
                </tr>

                {{-- Lines, taxes and total. --}}
                <tr>
                    <td style="padding:16px 24px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;border-top:1px solid #e2e8f0;">
                            @foreach ($print['items'] as $item)
                                <tr>
                                    <td style="padding:10px 0;border-bottom:1px solid #f1f5f9;vertical-align:top;">
                                        <div>{{ $item['description'] }}</div>
                                        @if ((float) $item['quantity'] !== 1.0)
                                            <div style="font-size:12px;color:#64748b;">{{ $item['quantity'] }} × {{ $item['unit_price'] }}</div>
                                        @endif
                                    </td>
                                    <td align="right" style="padding:10px 0 10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top;white-space:nowrap;">{{ $item['total'] }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:10px 0 2px;color:#475569;">{{ __('billing.subtotal') }}</td>
                                <td align="right" style="padding:10px 0 2px;color:#475569;white-space:nowrap;">{{ $print['subtotal'] }}</td>
                            </tr>
                            @if ($print['discount'])
                                <tr>
                                    <td style="padding:2px 0;color:#475569;">{{ __('billing.discount') }}</td>
                                    <td align="right" style="padding:2px 0;color:#475569;white-space:nowrap;">{{ $print['discount'] }}</td>
                                </tr>
                            @endif
                            @foreach ($print['taxes'] as $tax)
                                <tr>
                                    <td style="padding:2px 0;color:#475569;">{{ $tax['label'] }}</td>
                                    <td align="right" style="padding:2px 0;color:#475569;white-space:nowrap;">{{ $tax['amount'] }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td style="padding:10px 0 0;font-size:16px;font-weight:bold;">{{ __('billing.total') }}</td>
                                <td align="right" style="padding:10px 0 0;font-size:16px;font-weight:bold;white-space:nowrap;">{{ $print['total'] }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- View receipt online and Download PDF. --}}
                <tr>
                    <td align="center" style="padding:12px 24px 8px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" bgcolor="{{ $color }}" style="border-radius:8px;">
                                    <a href="{{ $url }}" style="display:inline-block;padding:14px 24px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">{{ __('documents.receipt.view') }}</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:14px 0 0;">
                            <a href="{{ $pdfUrl }}" style="font-size:14px;font-weight:bold;color:{{ $color }};text-decoration:underline;">{{ __('documents.receipt.pdf') }}</a>
                        </p>
                    </td>
                </tr>

                {{-- The brand's contacts. --}}
                <tr>
                    <td align="center" style="padding:20px 24px 28px;font-size:13px;line-height:20px;color:#64748b;">
                        <div style="font-weight:bold;color:#0f172a;">{{ $brandName }}</div>
                        @if ($brand['address'])<div>{{ $brand['address'] }}</div>@endif
                        <div>
                            {!! collect([
                                $brand['phone'] ? '<a href="tel:'.e(preg_replace('/[^+0-9]/', '', $brand['phone'])).'" style="color:#64748b;">'.e($brand['phone']).'</a>' : null,
                                $brand['email'] ? '<a href="mailto:'.e($brand['email']).'" style="color:#64748b;">'.e($brand['email']).'</a>' : null,
                                $brand['website'] ? '<a href="'.e(str_starts_with($brand['website'], 'http') ? $brand['website'] : 'https://'.$brand['website']).'" style="color:#64748b;">'.e($brand['website']).'</a>' : null,
                            ])->filter()->implode(' · ') !!}
                        </div>
                        <div style="margin-top:10px;">{{ __('documents.receipt.thanks') }}</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
