import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Download, Landmark } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { currencyDecimals, useMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { IconButton } from '@/components/ui/icon-button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { expensesHub } from '@/routes';
import {
    exportMethod as exportCsv,
    index,
    settings as saveSettings,
} from '@/routes/tax-reserve';
import {
    destroy as destroyTransfer,
    store as storeTransfer,
} from '@/routes/tax-reserve/transfers';

type Settings = {
    enabled: boolean;
    rate: string;
    deduct_expense_tax: boolean;
    basis: 'paid' | 'issued';
    reminder_day: number | null;
};

type Reserve = {
    month: string;
    currency: string;
    basis: 'paid' | 'issued';
    rate: string;
    taxes: {
        name: string;
        collected: number;
        deducted: number;
        amount: number;
    }[];
    profit: number;
    income_tax: number;
    total: number;
    transferred: number;
    left: number;
};

type Transfer = {
    id: number;
    month: string;
    amount: number;
    currency: string;
    transferred_on: string;
    note: string | null;
    by: string | null;
};

/** "October 2026" for a "2026-10" or "2026-10-01". */
function useMonthName() {
    const { auth } = usePage().props;
    const format = new Intl.DateTimeFormat(auth.company?.locale ?? 'en-US', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

    return (value: string) =>
        format.format(new Date(`${value.slice(0, 7)}-01T12:00:00Z`));
}

function shiftMonth(value: string, by: number): string {
    const [year, month] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1 + by, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/**
 * Expenses & taxes → Tax reserve (UX-REDESIGN §17, Owner): "Move to the tax account for September" with each sales
 * tax, the income and dividend tax and the total; Mark transfer; earlier months still short; the transfers; the
 * settings.
 */
export default function TaxReserve({
    settings,
    reserve,
    behind,
    month,
    currentMonth,
    today,
    transfers,
}: {
    settings: Settings;
    reserve: Reserve | null;
    behind: { month: string; left: number }[];
    month: string;
    currentMonth: string;
    today: string;
    transfers: Transfer[];
}) {
    const t = useTrans();
    const money = useMoney();
    const time = useCompanyTime();
    const monthName = useMonthName();
    const [markOpen, setMarkOpen] = useState(false);
    const [removing, setRemoving] = useState<Transfer | null>(null);

    const go = (value: string) =>
        router.get(
            index({ query: { month: value } }).url,
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    return (
        <>
            <Head title={t('tax_reserve.title')} />

            <ScreenHeader
                title={t('tax_reserve.title')}
                back={expensesHub()}
                actions={
                    <ActionSheet
                        title={t('tax_reserve.title')}
                        items={[
                            {
                                key: 'export',
                                label: t('tax_reserve.export'),
                                icon: <Download />,
                                onSelect: () => {
                                    window.location.href = exportCsv({
                                        query: { year: month.slice(0, 4) },
                                    }).url;
                                },
                            },
                        ]}
                    />
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 sm:p-6">
                {reserve ? (
                    <>
                        <div className="flex items-center gap-2">
                            <IconButton
                                label={t('calendar.previous')}
                                onClick={() => go(shiftMonth(month, -1))}
                            >
                                <ChevronLeft />
                            </IconButton>
                            <span className="flex-1 text-center font-semibold">
                                {monthName(month)}
                            </span>
                            <IconButton
                                label={t('calendar.next')}
                                disabled={month >= currentMonth}
                                onClick={() => go(shiftMonth(month, 1))}
                            >
                                <ChevronRight />
                            </IconButton>
                        </div>

                        <section
                            className="da-card space-y-3 p-4"
                            data-test="tax-reserve-card"
                        >
                            <h2 className="text-base font-semibold">
                                {t('tax_reserve.card_title', {
                                    month: monthName(reserve.month),
                                })}
                            </h2>
                            <dl className="space-y-2 text-sm">
                                {reserve.taxes.length === 0 && (
                                    <p className="text-muted-foreground">
                                        {t('tax_reserve.no_taxes')}
                                    </p>
                                )}
                                {reserve.taxes.map((tax) => (
                                    <div
                                        key={tax.name}
                                        className="flex items-baseline justify-between gap-3"
                                    >
                                        <dt className="min-w-0">
                                            <span className="font-medium">
                                                {tax.name}
                                            </span>
                                            {tax.deducted > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        'tax_reserve.collected',
                                                        {
                                                            amount: money(
                                                                tax.collected,
                                                            ),
                                                        },
                                                    )}{' '}
                                                    {t('tax_reserve.deducted', {
                                                        amount: money(
                                                            tax.deducted,
                                                        ),
                                                    })}
                                                </span>
                                            )}
                                        </dt>
                                        <dd className="tabular-nums">
                                            {money(tax.amount)}
                                        </dd>
                                    </div>
                                ))}
                                <div className="flex items-baseline justify-between gap-3">
                                    <dt className="min-w-0 font-medium">
                                        {t('tax_reserve.income_tax', {
                                            rate: Number(reserve.rate),
                                            profit: money(reserve.profit),
                                        })}
                                        {reserve.profit <= 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {t('tax_reserve.loss')}
                                            </span>
                                        )}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {money(reserve.income_tax)}
                                    </dd>
                                </div>
                                <div className="flex items-baseline justify-between gap-3 border-t pt-3">
                                    <dt className="text-base font-semibold">
                                        {t('tax_reserve.total')}
                                    </dt>
                                    <dd
                                        className="text-2xl font-bold tabular-nums"
                                        data-test="tax-reserve-total"
                                    >
                                        {money(reserve.total)}
                                    </dd>
                                </div>
                                {reserve.transferred > 0 && (
                                    <div className="flex justify-between text-muted-foreground">
                                        <dt>{t('tax_reserve.moved')}</dt>
                                        <dd className="tabular-nums">
                                            {money(reserve.transferred)}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                            <p className="text-xs text-muted-foreground">
                                {t(`tax_reserve.basis_${reserve.basis}`)}
                            </p>
                            {reserve.left > 0 ? (
                                <Button
                                    className="h-12 w-full text-base"
                                    onClick={() => setMarkOpen(true)}
                                    data-test="mark-transfer"
                                >
                                    <Landmark />{' '}
                                    {t('tax_reserve.transfer.mark')} ·{' '}
                                    {money(reserve.left)}
                                </Button>
                            ) : (
                                reserve.total > 0 && (
                                    <p className="text-sm font-medium text-success">
                                        {t('tax_reserve.done')}
                                    </p>
                                )
                            )}
                        </section>

                        {behind.length > 0 && (
                            <section className="da-card space-y-2 p-4">
                                <h2 className="text-base font-semibold text-danger">
                                    {t('tax_reserve.behind_title')}
                                </h2>
                                <ul className="divide-y text-sm">
                                    {behind.map((row) => (
                                        <li key={row.month}>
                                            <button
                                                type="button"
                                                className="flex min-h-11 w-full items-center justify-between"
                                                onClick={() =>
                                                    go(row.month.slice(0, 7))
                                                }
                                            >
                                                <span>
                                                    {monthName(row.month)}
                                                </span>
                                                <span className="font-semibold tabular-nums">
                                                    {money(row.left)}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="space-y-2">
                            <h2 className="text-base font-semibold">
                                {t('tax_reserve.history')}
                            </h2>
                            {transfers.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('tax_reserve.no_history')}
                                </p>
                            ) : (
                                <ul className="da-card divide-y overflow-hidden text-sm">
                                    {transfers.map((transfer) => (
                                        <li
                                            key={transfer.id}
                                            className="flex items-center gap-3 px-3 py-2.5"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium">
                                                    {monthName(transfer.month)}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {[
                                                        time.dateOnly(
                                                            transfer.transferred_on,
                                                        ),
                                                        transfer.by,
                                                        transfer.note,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            <span className="font-semibold tabular-nums">
                                                {money(
                                                    transfer.amount,
                                                    transfer.currency,
                                                )}
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setRemoving(transfer)
                                                }
                                            >
                                                {t(
                                                    'tax_reserve.transfer.remove',
                                                )}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                ) : (
                    <div className="da-card">
                        <EmptyState
                            icon={Landmark}
                            title={t('tax_reserve.title')}
                            description={t('tax_reserve.off')}
                        />
                    </div>
                )}

                <SettingsForm settings={settings} />
            </div>

            {reserve && (
                <MarkTransferDialog
                    open={markOpen}
                    onOpenChange={setMarkOpen}
                    month={month}
                    monthLabel={monthName(month)}
                    amount={reserve.left}
                    today={today}
                />
            )}
            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={t('tax_reserve.transfer.remove')}
                description={t('tax_reserve.transfer.confirm_remove')}
                confirmLabel={t('tax_reserve.transfer.remove')}
                destructive
                onConfirm={() => {
                    if (removing) {
                        router.delete(destroyTransfer(removing.id).url, {
                            preserveScroll: true,
                        });
                    }

                    setRemoving(null);
                }}
            />
        </>
    );
}

function SettingsForm({ settings }: { settings: Settings }) {
    const t = useTrans();
    const form = useForm({
        enabled: settings.enabled,
        rate: settings.rate,
        deduct_expense_tax: settings.deduct_expense_tax,
        basis: settings.basis,
        reminder_day: settings.reminder_day
            ? String(settings.reminder_day)
            : '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            reminder_day: d.reminder_day === '' ? null : Number(d.reminder_day),
        }));
        form.put(saveSettings().url, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="da-card space-y-4 p-4"
            data-test="tax-reserve-settings"
        >
            <h2 className="text-base font-semibold">
                {t('tax_reserve.settings.title')}
            </h2>
            <label className="flex min-h-11 items-center gap-3 text-sm font-medium">
                <Checkbox
                    checked={form.data.enabled}
                    onCheckedChange={(c) => form.setData('enabled', c === true)}
                />
                {t('tax_reserve.settings.enabled')}
            </label>
            {form.data.enabled && (
                <>
                    <FormField
                        id="tax-reserve-rate"
                        label={t('tax_reserve.settings.rate')}
                        error={form.errors.rate}
                    >
                        <Input
                            id="tax-reserve-rate"
                            inputMode="decimal"
                            className="w-28"
                            value={form.data.rate}
                            onChange={(e) =>
                                form.setData('rate', e.target.value)
                            }
                        />
                    </FormField>
                    <div className="space-y-1">
                        <p className="text-sm font-medium">
                            {t('tax_reserve.settings.basis')}
                        </p>
                        <SegmentedControl
                            label={t('tax_reserve.settings.basis')}
                            value={form.data.basis}
                            onChange={(basis) => form.setData('basis', basis)}
                            options={[
                                {
                                    value: 'paid',
                                    label: t('tax_reserve.settings.basis_paid'),
                                },
                                {
                                    value: 'issued',
                                    label: t(
                                        'tax_reserve.settings.basis_issued',
                                    ),
                                },
                            ]}
                        />
                    </div>
                    <label className="flex min-h-11 items-start gap-3 text-sm">
                        <Checkbox
                            className="mt-0.5"
                            checked={form.data.deduct_expense_tax}
                            onCheckedChange={(c) =>
                                form.setData('deduct_expense_tax', c === true)
                            }
                        />
                        <span>
                            <span className="block font-medium">
                                {t('tax_reserve.settings.deduct_expense_tax')}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {t(
                                    'tax_reserve.settings.deduct_expense_tax_hint',
                                )}
                            </span>
                        </span>
                    </label>
                    <FormField
                        id="tax-reserve-day"
                        label={t('tax_reserve.settings.reminder_day')}
                        error={form.errors.reminder_day}
                    >
                        <NativeSelect
                            id="tax-reserve-day"
                            className="w-40"
                            value={form.data.reminder_day}
                            onChange={(e) =>
                                form.setData('reminder_day', e.target.value)
                            }
                        >
                            <option value="">
                                {t('tax_reserve.settings.reminder_none')}
                            </option>
                            {Array.from({ length: 28 }, (_, i) => i + 1).map(
                                (day) => (
                                    <option key={day} value={day}>
                                        {day}
                                    </option>
                                ),
                            )}
                        </NativeSelect>
                    </FormField>
                </>
            )}
            <Button
                type="submit"
                variant="outline"
                className="h-11"
                disabled={form.processing}
            >
                {t('tax_reserve.settings.save')}
            </Button>
        </form>
    );
}

function MarkTransferDialog({
    open,
    onOpenChange,
    month,
    monthLabel,
    amount,
    today,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    month: string;
    monthLabel: string;
    amount: number;
    today: string;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const currency = auth.company?.currency ?? 'USD';
    const decimals = currencyDecimals(currency);
    const form = useForm({
        month,
        amount: (amount / 10 ** decimals).toFixed(decimals),
        transferred_on: today,
        note: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(storeTransfer().url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('tax_reserve.transfer.title', { month: monthLabel })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('tax_reserve.hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <FormField
                        id="transfer-amount"
                        label={t('tax_reserve.transfer.amount')}
                        error={form.errors.amount}
                    >
                        <Input
                            id="transfer-amount"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="transfer-date"
                        label={t('tax_reserve.transfer.date')}
                        error={form.errors.transferred_on}
                    >
                        <Input
                            id="transfer-date"
                            type="date"
                            value={form.data.transferred_on}
                            onChange={(e) =>
                                form.setData('transferred_on', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="transfer-note"
                        label={t('tax_reserve.transfer.note')}
                        error={form.errors.note}
                    >
                        <Input
                            id="transfer-note"
                            maxLength={500}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </FormField>
                    <Button
                        type="submit"
                        className="h-11 w-full"
                        disabled={form.processing}
                    >
                        {t('tax_reserve.transfer.save')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

TaxReserve.layout = {
    breadcrumbs: [{ title: 'tax_reserve.title', href: index() }],
};
