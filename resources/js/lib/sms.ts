/**
 * An sms: link that opens the phone's messages app with the number and the text ready.
 * iOS expects "sms:+15551234567&body=…", Android (and most others) "sms:+15551234567?body=…".
 */
export function smsUrl(
    to: string,
    body: string,
    userAgent = navigator.userAgent,
): string {
    const ios =
        /iPhone|iPad|iPod/i.test(userAgent) ||
        (/Macintosh/.test(userAgent) && 'ontouchend' in document);
    const number = to.replace(/[^\d+]/g, '');

    return `sms:${number}${ios ? '&' : '?'}body=${encodeURIComponent(body)}`;
}
