import { Head, Link, router, useForm } from '@inertiajs/react';
import { Banknote, RotateCcw } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { fromMinor, useMoney } from '@/components/billing/money';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { PaginationLinks } from '@/components/pagination-links';
import type { Paginated } from '@/components/pagination-links';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { deposit, index, reverse } from '@/routes/cash';
import { show as showInvoice } from '@/routes/invoices';

type Person = { id: number; name: string; balances: Record<string, number> };

type Movement = {
    id: number;
    type: string;
    type_label: string;
    amount: number;
    currency: string;
    occurred_on: string;
    user: string | null;
    by: string | null;
    note: string | null;
    invoice: { id: number; number: string } | null;
    can_reverse: boolean;
};

/**
 * Cash on hand per person, cash deposits to the office and the journal (office).
 */
export default function CashIndex({
    people,
    movements,
    currency,
    acceptsCash,
    today,
}: {
    people: Person[];
    movements: Paginated<Movement>;
    currency: string;
    acceptsCash: boolean;
    today: string;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const time = useCompanyTime();
    const [open, setOpen] = useState(false);
    const form = useForm({
        user_id: String(people[0]?.id ?? ''),
        amount: '',
        date: today,
        note: '',
    });

    const openFor = (person: Person) => {
        form.setData({
            ...form.data,
            user_id: String(person.id),
            amount:
                (person.balances[currency] ?? 0) > 0
                    ? fromMinor(person.balances[currency] ?? 0, currency)
                    : '',
        });
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(deposit().url, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    const undo = (m: Movement) => {
        const reason = prompt(t('cash.reverse_prompt'));

        if (reason && reason.trim()) {
            router.post(
                reverse(m.id).url,
                { reason },
                { preserveScroll: true },
            );
        }
    };

    return (
        <>
            <Head title={t('cash.title')} />

            <div className="max-w-3xl space-y-6 p-4">
                <PageHeader
                    title={t('cash.title')}
                    description={t('cash.hint')}
                />

                {!acceptsCash && (
                    <p className="rounded-lg bg-muted p-3 text-sm">
                        {t('cash.cash_disabled')}
                    </p>
                )}

                <ul className="divide-y rounded-lg border">
                    {people.map((p) => {
                        const entries = Object.entries(p.balances).filter(
                            ([, amount]) => amount !== 0,
                        );

                        return (
                            <li
                                key={p.id}
                                className="flex items-center gap-3 p-3 text-sm"
                            >
                                <Link
                                    href={index({ query: { user: p.id } })}
                                    className="min-w-0 flex-1 font-medium hover:underline"
                                >
                                    {p.name}
                                </Link>
                                <span className="tabular-nums">
                                    {entries.length === 0
                                        ? t('cash.nothing')
                                        : entries
                                              .map(([cur, amount]) =>
                                                  money(amount, cur),
                                              )
                                              .join(' · ')}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => openFor(p)}
                                >
                                    <Banknote /> {t('cash.record_deposit')}
                                </Button>
                            </li>
                        );
                    })}
                </ul>

                <section className="space-y-2">
                    <h2 className="text-base font-medium">
                        {t('cash.journal')}
                    </h2>
                    {movements.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('cash.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {movements.data.map((m) => (
                                <li
                                    key={m.id}
                                    className="flex items-start gap-3 p-3 text-sm"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            {m.type_label} · {m.user}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {[
                                                time.dateOnly(m.occurred_on),
                                                m.by,
                                                m.note,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        {m.invoice && (
                                            <Link
                                                href={showInvoice(m.invoice.id)}
                                                className="text-xs underline"
                                            >
                                                {m.invoice.number}
                                            </Link>
                                        )}
                                    </div>
                                    <span
                                        className={
                                            m.amount < 0
                                                ? 'text-red-700 tabular-nums dark:text-red-400'
                                                : 'tabular-nums'
                                        }
                                    >
                                        {money(m.amount, m.currency)}
                                    </span>
                                    {m.can_reverse && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-9"
                                            aria-label={t('cash.reverse')}
                                            onClick={() => undo(m)}
                                        >
                                            <RotateCcw />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    <PaginationLinks links={movements.links} />
                </section>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('cash.deposit_title')}</DialogTitle>
                        <DialogDescription>{t('cash.hint')}</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <FormField
                            id="cash-person"
                            label={t('cash.person')}
                            error={form.errors.user_id}
                        >
                            <NativeSelect
                                id="cash-person"
                                value={form.data.user_id}
                                onChange={(e) =>
                                    form.setData('user_id', e.target.value)
                                }
                            >
                                {people.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="cash-amount"
                            label={t('payments.fields.amount')}
                            error={form.errors.amount}
                        >
                            <Input
                                id="cash-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData('amount', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="cash-date"
                            label={t('cash.date')}
                            error={form.errors.date}
                        >
                            <Input
                                id="cash-date"
                                type="date"
                                value={form.data.date}
                                onChange={(e) =>
                                    form.setData('date', e.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="cash-note"
                            label={t('cash.note')}
                            error={form.errors.note}
                        >
                            <Input
                                id="cash-note"
                                value={form.data.note}
                                maxLength={500}
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
                            {t('cash.record_deposit')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

CashIndex.layout = {
    breadcrumbs: [{ title: 'cash.title', href: index() }],
};
