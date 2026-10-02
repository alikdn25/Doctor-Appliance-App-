{{-- A customer message sent by email (links in the text become clickable). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="height:5px;background:{{ $color }};"></td></tr>
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 16px;font-size:18px;font-weight:bold;">{{ $brandName }}</p>
                <div style="font-size:14px;line-height:1.5;white-space:pre-line;">{!! preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:'.e($color).';">$1</a>', e($body)) !!}</div>
            </td>
        </tr>
    </table>
</body>
</html>
