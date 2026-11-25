{{-- Booking confirmation: in the style of the invoice emails (tables and inline styles only). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="height:5px;background:{{ $color }};"></td></tr>
        <tr>
            <td style="padding:24px;">
                @if ($logo)
                <img src="{{ $logo }}" alt="{{ $brandName }}" style="display:block;max-height:56px;max-width:220px;margin:0 0 12px;border:0;">
                @else
                <p style="margin:0 0 16px;font-size:18px;font-weight:bold;">{{ $brandName }}</p>
                @endif
                <p style="margin:0 0 12px;font-size:20px;font-weight:bold;color:{{ $color }};">{{ __('booking_confirmation.email.heading') }}</p>
                <div style="font-size:14px;line-height:1.5;white-space:pre-line;">{{ $body }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    @foreach ([
                        'when' => $details['when'],
                        'address' => $details['address'],
                        'service' => $details['service'],
                        'phone' => $details['phone'],
                    ] as $key => $value)
                    @if (filled($value))
                    <tr>
                        <td style="padding:10px 16px;width:90px;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#6b7280;vertical-align:top;">{{ __('booking_confirmation.email.'.$key) }}</td>
                        <td style="padding:10px 16px;font-size:15px;font-weight:bold;color:#111827;">{{ $value }}</td>
                    </tr>
                    @endif
                    @endforeach
                </table>
                @if ($policy !== '')
                <p style="margin:0;font-size:13px;line-height:1.5;color:#374151;border-left:3px solid {{ $color }};padding-left:10px;">{{ $policy }}</p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
