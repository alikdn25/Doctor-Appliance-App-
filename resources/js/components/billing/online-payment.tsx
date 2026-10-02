import { router } from '@inertiajs/react';
import { Copy, ExternalLink, QrCode, RefreshCw } from 'lucide-react';
import QRCode from 'qrcode';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { useMoney } from '@/components/billing/money';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { paymentLink } from '@/routes/invoices';

export type OnlinePayment = {
    provider: string;
    link: { url: string; amount: number; currency: string } | null;
} | null;

/**
 * "Pay online": a provider payment link for the invoice balance, shown as a big QR code for the customer
 * to scan on site, plus copy / open. One tap to make the link; a new one when the balance changed.
 */
export function OnlinePaymentSection({
    invoiceId,
    online,
    balance,
    currency,
}: {
    invoiceId: number;
    online: NonNullable<OnlinePayment>;
    balance: number;
    currency: string;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const [qr, setQr] = useState<string | null>(null);
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const url = online.link?.url ?? null;

    useEffect(() => {
        let cancelled = false;

        if (url) {
            QRCode.toDataURL(url, { margin: 1, width: 480 })
                .then((data) => !cancelled && setQr(data))
                .catch(() => !cancelled && setQr(null));
        }

        return () => {
            cancelled = true;
        };
    }, [url]);

    const create = () =>
        router.post(
            paymentLink(invoiceId).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => setError(undefined),
                onError: (errors) => setError(errors.payment_link),
            },
        );

    const copy = async () => {
        if (!url) {
            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            toast.success(t('payments.links.copied'));
        } catch {
            window.prompt(t('payments.links.copy'), url);
        }
    };

    return (
        <section className="space-y-3 rounded-lg border p-4">
            <div>
                <h2 className="text-base font-medium">
                    {t('payments.links.title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('payments.links.hint', { provider: online.provider })}
                </p>
            </div>

            {url ? (
                <div className="space-y-3">
                    <p className="text-sm font-medium">
                        {t('payments.links.amount', {
                            amount: money(online.link?.amount ?? balance),
                        })}
                    </p>
                    {qr && (
                        <img
                            src={qr}
                            alt={t('payments.links.title')}
                            className="mx-auto aspect-square w-full max-w-64 rounded-md bg-white p-2"
                        />
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={copy}
                        >
                            <Copy /> {t('payments.links.copy')}
                        </Button>
                        <Button variant="outline" className="h-11" asChild>
                            <a href={url} target="_blank" rel="noreferrer">
                                <ExternalLink /> {t('payments.links.open')}
                            </a>
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('payments.links.paid_automatically')}
                    </p>
                </div>
            ) : (
                <Button
                    type="button"
                    className="h-12 w-full"
                    disabled={busy}
                    onClick={create}
                >
                    {busy ? <RefreshCw className="animate-spin" /> : <QrCode />}
                    {t('payments.links.create')} · {money(balance)}
                </Button>
            )}
            <InputError message={error} />
        </section>
    );
}
