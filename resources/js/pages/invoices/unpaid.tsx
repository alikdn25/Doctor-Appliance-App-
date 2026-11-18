import { Head, Link, router } from '@inertiajs/react';
import { FileUp, MessageSquareText } from 'lucide-react';
import { useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FilterChips } from '@/components/ui/filter-chips';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show, unpaid as unpaidRoute } from '@/routes/invoices';
import * as debtors from '@/routes/invoices/debtors';
import * as reminders from '@/routes/invoices/reminders';
import { create as createInvoice } from '@/routes/invoices';
import { show as showJob } from '@/routes/jobs';

type Row = {
    id: number;
    number: string;
    customer: string | null;
    issued_on: string;
    balance: string;
    needs_amount: boolean;
    sent: boolean;
    status: 'not_reminded' | 'reminded' | 'answered';
    reminded_at: string | null;
};
type NoInvoice = {
    id: number;
    number: string;
    customer: string | null;
    description: string | null;
};
type Preview = {
    id: number;
    number: string;
    customer: string | null;
    phone: string | null;
    text: string;
};

const FILTERS = [
    'all',
    'not_reminded',
    'reminded',
    'answered',
    'no_invoice',
] as const;

/**
 * Unpaid (owner's task of November 19): open invoices with the reminder state, a soft reminder for one or several
 * (preview → Confirm; no amount and no link), and Completed jobs without an invoice.
 */
export default function Unpaid({
    filter,
    invoices,
    noInvoice,
    noInvoiceCount,
    canImport,
    canRemind,
}: {
    filter: string;
    invoices: Row[];
    noInvoice: NoInvoice[];
    noInvoiceCount: number;
    canImport: boolean;
    canRemind: boolean;
}) {
    const t = useTrans();
    const [selected, setSelected] = useState<number[]>([]);
    const [preview, setPreview] = useState<Preview[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const toggle = (id: number) =>
        setSelected((ids) =>
            ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id],
        );

    const openPreview = async () => {
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(reminders.preview().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ invoice_ids: selected }),
            });
            const data = (await response.json()) as {
                texts?: Preview[];
                message?: string;
            };
            if (!response.ok) throw new Error(data.message ?? '');
            setPreview(data.texts ?? []);
        } catch (e) {
            setError(e instanceof Error && e.message ? e.message : '…');
        } finally {
            setBusy(false);
        }
    };

    const confirm = () =>
        router.post(
            reminders.send().url,
            { invoice_ids: selected },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPreview(null);
                    setSelected([]);
                },
            },
        );

    return (
        <>
            <Head title={t('debtors.title')} />
            <ScreenHeader
                title={t('debtors.title')}
                actions={
                    canImport ? (
                        <Button asChild variant="outline" size="sm">
                            <Link href={debtors.importMethod()}>
                                <FileUp className="size-4" />
                                {t('debtors.import.open')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 pb-32 sm:p-6">
                <p className="text-sm text-muted-foreground">
                    {t('debtors.description')}
                </p>
                <FilterChips
                    label={t('debtors.title')}
                    selected={[filter]}
                    onChange={(value) =>
                        router.get(
                            unpaidRoute().url,
                            { filter: value },
                            { preserveScroll: true },
                        )
                    }
                    chips={FILTERS.map((value) => ({
                        value,
                        label:
                            value === 'no_invoice'
                                ? `${t('debtors.filters.no_invoice')} (${noInvoiceCount})`
                                : t(`debtors.filters.${value}`),
                    }))}
                />

                {filter !== 'no_invoice' && (
                    <ul
                        className="da-card divide-y"
                        data-test="unpaid-invoices"
                    >
                        {invoices.length === 0 && (
                            <li className="p-4 text-sm text-muted-foreground">
                                {t('debtors.empty')}
                            </li>
                        )}
                        {invoices.map((row) => (
                            <li
                                key={row.id}
                                className="flex items-start gap-3 p-3"
                            >
                                {canRemind && (
                                    <Checkbox
                                        className="mt-1"
                                        checked={selected.includes(row.id)}
                                        onCheckedChange={() => toggle(row.id)}
                                        aria-label={row.number}
                                    />
                                )}
                                <Link
                                    href={show(row.id)}
                                    className="min-w-0 flex-1 space-y-0.5"
                                >
                                    <p className="flex flex-wrap items-center gap-2 font-semibold">
                                        {row.customer ?? '—'}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {row.number} · {row.issued_on}
                                        </span>
                                    </p>
                                    <p className="flex flex-wrap gap-2 text-xs">
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5',
                                                row.status === 'answered'
                                                    ? 'bg-emerald-100 text-emerald-900'
                                                    : row.status === 'reminded'
                                                      ? 'bg-sky-100 text-sky-900'
                                                      : 'bg-muted',
                                            )}
                                        >
                                            {t(`debtors.status.${row.status}`)}
                                        </span>
                                        {!row.sent && (
                                            <span className="rounded-full bg-muted px-2 py-0.5">
                                                {t('debtors.not_sent')}
                                            </span>
                                        )}
                                        {row.needs_amount && (
                                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900">
                                                {t('debtors.needs_amount')}
                                            </span>
                                        )}
                                    </p>
                                </Link>
                                <span className="font-semibold tabular-nums">
                                    {row.needs_amount ? '—' : row.balance}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {noInvoice.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-semibold">
                            {t('debtors.no_invoice_title')}
                        </h2>
                        <ul className="da-card divide-y">
                            {noInvoice.map((job) => (
                                <li
                                    key={job.id}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <Link
                                        href={showJob(job.id)}
                                        className="min-w-0 flex-1"
                                    >
                                        <p className="font-semibold">
                                            #{job.number} {job.customer}
                                        </p>
                                        {job.description && (
                                            <p className="truncate text-sm text-muted-foreground">
                                                {job.description}
                                            </p>
                                        )}
                                    </Link>
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={createInvoice(job.id)}>
                                            {t('debtors.new_invoice')}
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {canRemind && selected.length > 0 && preview === null && (
                <div className="fixed inset-x-0 bottom-[calc(var(--app-tabbar-h,0px)+0.75rem)] z-40 mx-auto flex w-full max-w-3xl items-center gap-3 px-4">
                    <Button
                        size="lg"
                        className="w-full"
                        loading={busy}
                        onClick={() => void openPreview()}
                        data-test="soft-reminder"
                    >
                        <MessageSquareText className="size-5" />
                        {t('debtors.reminder.button')} ·{' '}
                        {t('debtors.selected', { count: selected.length })}
                    </Button>
                    {error && (
                        <p className="text-sm text-red-700" role="alert">
                            {error}
                        </p>
                    )}
                </div>
            )}

            {preview !== null && (
                <div
                    role="dialog"
                    aria-modal="true"
                    className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center"
                >
                    <div className="max-h-[85vh] w-full max-w-lg space-y-3 overflow-y-auto rounded-t-2xl bg-background p-4 sm:rounded-2xl">
                        <h2 className="text-lg font-semibold">
                            {t('debtors.reminder.preview_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('debtors.reminder.preview_hint')}
                        </p>
                        {preview.map((item) => (
                            <div
                                key={item.id}
                                className="space-y-1 rounded-xl bg-muted p-3"
                            >
                                <p className="text-xs font-semibold">
                                    {item.customer} · {item.number}
                                    {item.phone ? ` · ${item.phone}` : ''}
                                </p>
                                <p className="text-sm whitespace-pre-line">
                                    {item.text}
                                </p>
                            </div>
                        ))}
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                className="flex-1"
                                onClick={() => setPreview(null)}
                            >
                                {t('debtors.reminder.cancel')}
                            </Button>
                            <Button
                                className="flex-1"
                                onClick={confirm}
                                data-test="soft-reminder-confirm"
                            >
                                {t('debtors.reminder.confirm')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
