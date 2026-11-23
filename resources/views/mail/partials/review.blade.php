{{-- The Google review block (link and optional picture) inside a customer email: invoice, receipt, review request.
     $review['text']: the review request text (HTML from ReviewText::html) in place of the standard title and line. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
    <tr>
        @if ($review['image'])
        <td width="110" valign="middle" style="width:110px;padding:16px 0 16px 16px;vertical-align:middle;">
            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="text-decoration:none;">
                <img src="{{ $review['image'] }}" width="110" height="110" alt="{{ __('documents.mail.review.image_alt') }}" style="display:block;width:110px;height:110px;border:0;outline:none;text-decoration:none;">
            </a>
        </td>
        @endif
        <td valign="middle" style="padding:16px;vertical-align:middle;font-size:14px;line-height:1.5;color:#1f2937;">
            @if ($review['text'] ?? null)
            <p style="margin:0 0 10px;font-size:14px;line-height:1.5;color:#374151;">{!! $review['text'] !!}</p>
            @else
            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="color:#1f2937;text-decoration:none;font-weight:bold;font-size:15px;">{{ __('documents.mail.review.title') }}</a>
            <p style="margin:6px 0 10px;font-size:14px;line-height:1.5;color:#374151;">{{ __('documents.mail.review.text') }}</p>
            @endif
            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="text-decoration:none;font-size:18px;letter-spacing:2px;">{{ __('documents.mail.review.stars') }}</a>
            <a href="{{ $review['url'] }}" target="_blank" rel="noopener" style="color:{{ $color }};text-decoration:none;font-weight:bold;font-size:14px;white-space:nowrap;">&nbsp;{{ __('documents.mail.review.cta') }}</a>
        </td>
    </tr>
</table>
