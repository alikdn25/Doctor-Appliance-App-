{{-- Email with an estimate or invoice. Plain, brand-colored, tables and inline styles only: works in every mail client. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($review)
    {{-- Narrow screens: the review picture goes above its text (clients without media queries keep two columns). --}}
    <style>
        @media only screen and (max-width: 480px) {
            .review-image, .review-text { display: block !important; width: 100% !important; box-sizing: border-box; }
            .review-image { padding: 0 0 12px 0 !important; text-align: center !important; }
            .review-image img { margin: 0 auto !important; }
        }
    </style>
    @endif
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="height:5px;background:{{ $color }};"></td></tr>
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 16px;font-size:18px;font-weight:bold;">{{ $brandName }}</p>
                <div style="font-size:14px;line-height:1.5;white-space:pre-line;">{{ $messageBefore }}</div>
                @if ($review)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
                    <tr>
                        @if ($review['image'])
                        <td class="review-image" width="110" valign="middle" style="width:110px;padding:16px 0 16px 16px;vertical-align:middle;">
                            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="text-decoration:none;">
                                <img src="{{ $review['image'] }}" width="110" height="110" alt="{{ __('documents.mail.review.image_alt') }}" style="display:block;width:110px;height:110px;border:0;outline:none;text-decoration:none;">
                            </a>
                        </td>
                        @endif
                        <td class="review-text" valign="middle" style="padding:16px;vertical-align:middle;font-size:14px;line-height:1.5;color:#1f2937;">
                            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="color:#1f2937;text-decoration:none;font-weight:bold;font-size:15px;">{{ __('documents.mail.review.title') }}</a>
                            <p style="margin:6px 0 10px;font-size:14px;line-height:1.5;color:#374151;">{{ __('documents.mail.review.text') }}</p>
                            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="text-decoration:none;font-size:18px;letter-spacing:2px;">{{ __('documents.mail.review.stars') }}</a>
                            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="color:{{ $color }};text-decoration:none;font-weight:bold;font-size:14px;white-space:nowrap;">&nbsp;{{ __('documents.mail.review.cta') }}</a>
                        </td>
                    </tr>
                </table>
                @endif
                @if ($messageAfter !== '')
                <div style="font-size:14px;line-height:1.5;white-space:pre-line;">{{ $messageAfter }}</div>
                @endif
                <p style="margin:24px 0;">
                    <a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:{{ $color }};color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;">
                        {{ __('documents.mail.view', ['kind' => mb_strtolower($kind)]) }}
                    </a>
                </p>
                <p style="margin:0;font-size:12px;color:#6b7280;">{{ __('documents.mail.link_hint') }}<br><a href="{{ $url }}" style="color:#6b7280;word-break:break-all;">{{ $url }}</a></p>
            </td>
        </tr>
    </table>
</body>
</html>
