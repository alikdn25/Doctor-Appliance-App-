import { Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    CreditCard,
    Landmark,
    RotateCcw,
    WifiOff,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import {
    ensureReaderAuthorized,
    hasCardReader,
    readerError,
    readerErrorText,
    SquareReader,
} from '@/lib/card-reader';
import type { ReaderErrorInfo } from '@/lib/card-reader';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { useOnline } from '@/lib/online';

export type ChargeScreen = {
    currency: string;
    taxes: { id: number; name: string; rate: string }[];
    default_tax_ids: number[];
    /** "Price includes tax" on a new screen: always off. */
    price_includes_tax: boolean;
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
          /** Square reference_id: the invoice number. */
          reference_id?: string | null;
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
 * Sends the failed card payment to the server (kept on the charge and written to storage/logs/card-payments.log):
 * the SDK's code, message, debugCode, details, and the phone, Android, SDK version, app build and environment.
 */
async function reportFailure(failUrl: string, info: ReaderErrorInfo) {
    let data = info.data;
    if (!data.sdkVersion) {
        // Errors raised on the web side (permissions, Square not connected) carry no diagnostics: ask the plugin.
        data = {
            ...(await SquareReader.diagnostics().catch(() => ({}))),
            ...data,
        };
    }

    await sendJson(failUrl, 'POST', {
        error: `${info.code}: ${info.message}`,
        code: info.code,
        message: info.message,
        debug_code: data.debugCode ?? null,
        debug_message: data.debugMessage ?? null,
        details: data.details ?? [],
        sdk_version: data.sdkVersion ?? null,
        device: data.device ?? null,
        android: data.android ?? null,
        app_version: data.appVersion ?? null,
        app_build: data.appBuild ?? null,
        application_id: data.applicationId ?? null,
        signing_sha256: data.signingSha256 ?? null,
        nfc: data.nfc ?? null,
        environment: data.environment ?? null,
        sdk_location_id: data.sdkLocationId ?? null,
        sdk_location_name: data.sdkLocationName ?? null,
        raw: data.raw ?? null,
    }).catch(() => {});
}

/** What the person sees when the reader or Square says no. */
export function cardErrorKey(code: string): string {
    const upper = code.toUpperCase();
    if (upper.includes('CANCEL')) return 'charges.take.cancelled';
    if (upper.includes('DECLIN')) return 'charges.take.declined';
    if (upper.includes('NETWORK') || upper === 'OFFLINE')
        return 'charges.take.offline';

    return 'charges.take.reader_problem';
}

/**
 * The one Take payment screen (owner's task of November 21) for a booking, an invoice and the "+": the amount on top
 * (the invoice balance, can be changed), then Card (the first and main button, with the glow; only in the Android
 * app — a browser shows a small "Card reader works in the Android app" line instead), Cash and E-transfer. Card: the
 * amount goes to the reader ("Tap, insert or swipe card", Cancel), the customer pays, the Paid screen opens. A
 * declined card or a reader error shows a plain message and Try again; nothing is recorded. Without internet Card
 * stays pressable but says that it needs a connection (no offline card payments).
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
    const online = useOnline();
    const { auth } = usePage<{ auth: { role?: { value: string } | null } }>()
        .props;
    const isOwner = auth?.role?.value === 'owner';
    const [value, setValue] = useState(amount);
    const [busy, setBusy] = useState<Method | null>(null);
    const [stage, setStage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    // The full SDK error (code, message, debugCode, details) under the plain message.
    const [errorDetails, setErrorDetails] = useState<string | null>(null);
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
        setErrorDetails(null);
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
                referenceId: started.reference_id ?? started.reference,
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
            const info = readerError(e);
            const { code, message } = info;
            if (failUrl) {
                setErrorDetails(readerErrorText(info));
                void reportFailure(failUrl, info);
            }
            setError(
                t(
                    navigator.onLine === false
                        ? 'charges.take.offline'
                        : cardErrorKey(code),
                    { error: message },
                ),
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
                    {errorDetails && (
                        <pre
                            className="overflow-x-auto rounded-xl bg-white/80 p-2 font-mono text-xs break-words whitespace-pre-wrap select-text dark:bg-black/30"
                            data-test="take-payment-error-details"
                        >
                            {errorDetails}
                        </pre>
                    )}
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
                    <div className="grid gap-2">
                        <Button
                            size="lg"
                            className="da-fab-glow min-h-16 text-lg"
                            loading={busy === 'card_reader'}
                            disabled={busy !== null && busy !== 'card_reader'}
                            onClick={() => void run('card_reader')}
                            data-test="charge-reader"
                        >
                            {error ? <RotateCcw /> : <CreditCard />}
                            {error
                                ? t('charges.take.try_again')
                                : t('charges.take.card')}
                        </Button>
                        {busy === 'card_reader' && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    void SquareReader.cancelPayment().catch(
                                        () => {},
                                    )
                                }
                                data-test="charge-reader-cancel"
                            >
                                {t('charges.take.cancel')}
                            </Button>
                        )}
                        {!online && (
                            <p
                                className="flex items-center justify-center gap-2 text-sm font-medium text-red-700 dark:text-red-300"
                                data-test="charge-reader-offline"
                            >
                                <WifiOff className="size-4" />
                                {t('charges.take.offline')}
                            </p>
                        )}
                    </div>
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

            {!native && (
                <p
                    className="text-center text-xs text-muted-foreground"
                    data-test="card-android-only"
                >
                    {isOwner ? (
                        <Link href="/android" className="underline">
                            {t('charges.take.android_only')}
                        </Link>
                    ) : (
                        t('charges.take.android_only')
                    )}
                </p>
            )}

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
