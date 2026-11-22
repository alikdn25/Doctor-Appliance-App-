import { router } from '@inertiajs/react';
import { Banknote, CreditCard, Landmark, RotateCcw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import {
    ensureReaderAuthorized,
    hasCardReader,
    readerError,
    SquareReader,
} from '@/lib/card-reader';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';

export type ChargeScreen = {
    currency: string;
    taxes: { name: string; rate: string }[];
    quick_amounts: { label: string; amount: string }[];
    methods: { cash: boolean; e_transfer_label: string };
    reader: { connected: boolean; session_url: string; settings_url: string };
    urls: { preview: string; lookup: string; store: string };
};

type Method = 'card_reader' | 'cash' | 'bank_transfer';

type StoreResponse =
    | { status: 'paid'; paid_url: string }
    | {
          status: 'pending';
          reference: string;
          amount: number;
          currency: string;
          note: string;
          complete_url: string;
          fail_url: string;
      };

async function post<T>(url: string, data: unknown): Promise<T> {
    const response = await sendJson(url, 'POST', data);
    const body = await response.json().catch(() => null);

    if (!response.ok) {
        const first = body?.errors
            ? (Object.values(body.errors)[0] as string[])[0]
            : null;
        throw new Error(first ?? body?.message ?? response.statusText);
    }

    return body as T;
}

/**
 * The one Take payment screen (owner's task of November 21) for a booking, an invoice and the "+": the amount on top
 * (the invoice balance, can be changed), then Charge reader (the main button, with the glow; only in the Android app),
 * Cash and E-transfer. Charge reader: the amount goes to the reader, the customer pays, the Paid screen opens. A
 * failed payment shows a plain message and Try again; nothing is recorded.
 */
export function TakePayment({
    screen,
    amount,
    amountLabel,
    editable,
    payload,
}: {
    screen: ChargeScreen;
    /** Plain number for the field, e.g. "168.00". */
    amount: string;
    /** Formatted for display, e.g. "$168.00". */
    amountLabel: string;
    editable: boolean;
    /** The body of POST charges for this method and amount. */
    payload: (method: Method, amount: string) => Record<string, unknown>;
}) {
    const t = useTrans();
    const native = hasCardReader();
    const [value, setValue] = useState(amount);
    const [busy, setBusy] = useState<Method | null>(null);
    const [stage, setStage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [confirm, setConfirm] = useState<Method | null>(null);

    // The paired reader connects as soon as the screen opens.
    useEffect(() => {
        if (native && screen.reader.connected) {
            ensureReaderAuthorized(screen.reader.session_url).catch(() => {});
        }
    }, [native, screen.reader.connected, screen.reader.session_url]);

    const shown =
        editable && value !== amount
            ? `${value} ${screen.currency}`
            : amountLabel;

    const run = async (method: Method) => {
        setBusy(method);
        setError(null);
        let failUrl: string | null = null;

        try {
            const started = await post<StoreResponse>(
                screen.urls.store,
                payload(method, value),
            );

            if (started.status === 'paid') {
                router.visit(started.paid_url);

                return;
            }

            failUrl = started.fail_url;
            setStage(t('charges.take.reader_connecting'));
            // Bluetooth and location are asked the first time the reader is used.
            const { granted } = await SquareReader.requestReaderPermissions();
            if (!granted) {
                throw Object.assign(
                    new Error(t('charges.reader.permissions')),
                    { code: 'PERMISSIONS' },
                );
            }
            await ensureReaderAuthorized(screen.reader.session_url);
            setStage(t('charges.take.reader_waiting'));
            const result = await SquareReader.charge({
                amount: started.amount,
                currency: started.currency,
                referenceId: started.reference,
                note: started.note,
                paymentAttemptId: started.reference,
            });
            setStage(t('charges.take.saving'));
            failUrl = null;
            const done = await post<{ paid_url: string }>(
                started.complete_url,
                { payment_id: result.paymentId },
            );
            router.visit(done.paid_url);
        } catch (e) {
            const { code, message } = readerError(e);
            if (failUrl) {
                void sendJson(failUrl, 'POST', {
                    error: `${code}: ${message}`,
                });
            }
            setError(
                code === 'CANCELED'
                    ? t('charges.take.cancelled')
                    : t('charges.take.failed', { error: message }),
            );
            setBusy(null);
            setStage(null);
        }
    };

    return (
        <div className="grid gap-4" data-test="take-payment">
            <div className="da-card grid gap-1 rounded-[18px] p-5 text-center">
                <p className="text-sm text-muted-foreground">
                    {t('charges.take.due')}
                </p>
                {editable ? (
                    <label className="mx-auto flex items-center gap-2">
                        <span className="sr-only">{t('charges.take.due')}</span>
                        <Input
                            type="text"
                            inputMode="decimal"
                            value={value}
                            onChange={(e) =>
                                setValue(e.target.value.replace(/[^0-9.]/g, ''))
                            }
                            className="h-14 w-48 text-center text-3xl font-bold"
                            data-test="take-payment-amount"
                        />
                    </label>
                ) : (
                    <p
                        className="text-4xl font-bold tracking-tight"
                        data-test="take-payment-amount"
                    >
                        {shown}
                    </p>
                )}
                <p className="text-xs text-muted-foreground">
                    {screen.currency}
                </p>
            </div>

            {error && (
                <div
                    role="alert"
                    className="grid gap-3 rounded-2xl border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100"
                    data-test="take-payment-error"
                >
                    <p>{error}</p>
                </div>
            )}

            {stage && (
                <p
                    className="text-center text-sm font-medium text-navy"
                    aria-live="polite"
                    data-test="take-payment-stage"
                >
                    {stage}
                </p>
            )}

            {native &&
                (screen.reader.connected ? (
                    <Button
                        size="lg"
                        className="da-fab-glow h-16 text-lg"
                        loading={busy === 'card_reader'}
                        disabled={busy !== null && busy !== 'card_reader'}
                        onClick={() => void run('card_reader')}
                        data-test="charge-reader"
                    >
                        {error ? <RotateCcw /> : <CreditCard />}
                        {error
                            ? t('charges.take.try_again')
                            : t('charges.take.charge_reader')}
                    </Button>
                ) : (
                    <p className="rounded-2xl bg-white/70 p-3 text-sm text-muted-foreground">
                        {t('charges.take.reader_not_connected')}
                    </p>
                ))}

            <div className="grid grid-cols-2 gap-3">
                {screen.methods.cash && (
                    <Button
                        variant="outline"
                        size="lg"
                        loading={busy === 'cash'}
                        disabled={busy !== null && busy !== 'cash'}
                        onClick={() => setConfirm('cash')}
                        data-test="charge-cash"
                    >
                        <Banknote /> {t('charges.take.cash')}
                    </Button>
                )}
                <Button
                    variant="outline"
                    size="lg"
                    className={screen.methods.cash ? '' : 'col-span-2'}
                    loading={busy === 'bank_transfer'}
                    disabled={busy !== null && busy !== 'bank_transfer'}
                    onClick={() => setConfirm('bank_transfer')}
                    data-test="charge-transfer"
                >
                    <Landmark /> {screen.methods.e_transfer_label}
                </Button>
            </div>

            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={
                    confirm === 'cash'
                        ? t('charges.take.confirm_cash', { amount: shown })
                        : t('charges.take.confirm_transfer', {
                              amount: shown,
                              method: screen.methods.e_transfer_label,
                          })
                }
                confirmLabel={
                    confirm === 'cash'
                        ? t('charges.take.cash')
                        : screen.methods.e_transfer_label
                }
                onConfirm={() => {
                    const method = confirm;
                    setConfirm(null);
                    if (method) void run(method);
                }}
            />
        </div>
    );
}
