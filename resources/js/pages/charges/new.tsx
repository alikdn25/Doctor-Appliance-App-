import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    CustomerFields,
    emptyCustomer,
} from '@/components/payments/customer-fields';
import type { CustomerDetails } from '@/components/payments/customer-fields';
import { TakePayment } from '@/components/payments/take-payment';
import type { ChargeScreen } from '@/components/payments/take-payment';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { hasCardReader } from '@/lib/card-reader';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';

type Line = { description: string; amount: string };

type Totals = {
    subtotal: string;
    taxes: { name: string; rate: string; amount: string }[];
    tax_total: string;
    total: string;
    total_minor: number;
};

type Props = ChargeScreen & {
    job: {
        id: number;
        number: string;
        customer: string | null;
        description: string | null;
        store_url: string;
    } | null;
};

/**
 * New charge ("+" → Charge reader) and the quick invoice of a job without one (owner's task of November 21): what
 * was done (one line; voice through the keyboard's microphone), the amount with or without tax, quick amounts from
 * the price list, taxes and the total in large type (worked out on the server), and the customer (collapsed, phone
 * first; a phone already in the database fills in that customer). Charge opens Take payment; the customer, a
 * Completed job and the paid invoice are made only after the payment.
 */
export default function NewCharge(props: Props) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country;
    const { job } = props;
    const [lines, setLines] = useState<Line[]>([
        { description: job?.description ?? '', amount: '' },
    ]);
    const [withTax, setWithTax] = useState<'with' | 'without'>('with');
    const [totals, setTotals] = useState<Totals | null>(null);
    const [customerOpen, setCustomerOpen] = useState(false);
    const [customer, setCustomer] = useState<CustomerDetails>(emptyCustomer);
    const [found, setFound] = useState<string | null>(null);
    const [paying, setPaying] = useState(false);
    const [saving, setSaving] = useState(false);
    const lookedUp = useRef('');

    const valid = lines.every((l) => Number(l.amount) > 0);
    const body = {
        lines,
        amounts_include_tax: withTax === 'with',
    };

    // Taxes and the total come from the server (company rates), shortly after typing stops.
    useEffect(() => {
        if (!valid) {
            setTotals(null);

            return;
        }
        const timer = window.setTimeout(() => {
            sendJson(props.urls.preview, 'POST', body)
                .then((r) => (r.ok ? r.json() : null))
                .then((data: Totals | null) => setTotals(data))
                .catch(() => setTotals(null));
        }, 250);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(body), valid]);

    const lookup = () => {
        const phone = customer.phone.replace(/\D/g, '');
        if (phone.length < 7 || lookedUp.current === phone) return;
        lookedUp.current = phone;
        fetch(
            `${props.urls.lookup}?phone=${encodeURIComponent(customer.phone)}`,
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        )
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                const c = data?.customer;
                if (!c) {
                    setFound(null);

                    return;
                }
                setFound(c.name);
                setCustomer((current) => ({
                    ...current,
                    first_name: c.first_name ?? '',
                    last_name: c.last_name ?? '',
                    email: c.email ?? current.email,
                    property: { ...current.property, ...c.property },
                    billing_same: !c.billing,
                    billing: { ...current.billing, ...c.billing },
                }));
            })
            .catch(() => {});
    };

    const setLine = (index: number, line: Partial<Line>) =>
        setLines(lines.map((l, i) => (i === index ? { ...l, ...line } : l)));

    const native = hasCardReader();
    const title = job
        ? t('charges.new.job_title', { number: job.number })
        : t(native ? 'charges.nav.charge_reader' : 'charges.new.title');

    if (paying && totals) {
        return (
            <>
                <Head title={t('charges.take.title')} />
                <ScreenHeader
                    title={t('charges.take.title')}
                    leading={
                        <Button
                            variant="ghost"
                            className="text-white"
                            onClick={() => setPaying(false)}
                            data-test="take-payment-back"
                        >
                            ←
                        </Button>
                    }
                />
                <div className="mx-auto w-full max-w-md p-4 pb-24 sm:p-6">
                    <TakePayment
                        screen={props}
                        amount={totals.total}
                        amountLabel={totals.total}
                        editable={false}
                        payload={(method) => ({
                            method,
                            draft: {
                                ...body,
                                customer: customerOpen ? customer : null,
                            },
                        })}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={title} />
            <ScreenHeader title={title} subtitle={job?.customer ?? undefined} />
            <div
                className="mx-auto grid w-full max-w-md gap-4 p-4 sm:p-6"
                data-test="new-charge"
            >
                {lines.map((line, index) => (
                    <div
                        key={index}
                        className="da-card grid gap-3 rounded-[18px] p-4"
                    >
                        <label className="grid gap-1 text-sm">
                            <span className="font-medium">
                                {t('charges.new.what')}
                            </span>
                            <Input
                                value={line.description}
                                placeholder={t('charges.new.what_placeholder')}
                                maxLength={500}
                                enterKeyHint="next"
                                onChange={(e) =>
                                    setLine(index, {
                                        description: e.target.value,
                                    })
                                }
                                data-test="charge-what"
                            />
                            {index === 0 && (
                                <span className="text-xs text-muted-foreground">
                                    {t('charges.new.what_hint')}
                                </span>
                            )}
                        </label>
                        <div className="flex items-end gap-2">
                            <label className="grid flex-1 gap-1 text-sm">
                                <span className="font-medium">
                                    {t('charges.new.amount')}
                                </span>
                                <Input
                                    type="text"
                                    inputMode="decimal"
                                    value={line.amount}
                                    placeholder="0.00"
                                    className="h-14 text-2xl font-bold"
                                    onChange={(e) =>
                                        setLine(index, {
                                            amount: e.target.value.replace(
                                                /[^0-9.]/g,
                                                '',
                                            ),
                                        })
                                    }
                                    data-test="charge-amount"
                                />
                            </label>
                            {lines.length > 1 && (
                                <Button
                                    variant="destructive"
                                    size="icon"
                                    aria-label={t('charges.new.remove_line')}
                                    onClick={() =>
                                        setLines(
                                            lines.filter((_, i) => i !== index),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </div>
                    </div>
                ))}

                {job && (
                    <Button
                        variant="tertiary"
                        onClick={() =>
                            setLines([
                                ...lines,
                                { description: '', amount: '' },
                            ])
                        }
                        data-test="charge-add-line"
                    >
                        <Plus /> {t('charges.new.add_line')}
                    </Button>
                )}

                <div className="grid gap-1">
                    <SegmentedControl
                        label={t('charges.new.amount')}
                        options={[
                            { value: 'with', label: t('charges.new.with_tax') },
                            {
                                value: 'without',
                                label: t('charges.new.without_tax'),
                            },
                        ]}
                        value={withTax}
                        onChange={setWithTax}
                    />
                    <p className="text-xs text-muted-foreground">
                        {withTax === 'with'
                            ? t('charges.new.with_tax_hint')
                            : t('charges.new.without_tax_hint')}
                    </p>
                </div>

                {props.quick_amounts.length > 0 && (
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">
                            {t('charges.new.quick')}
                        </p>
                        <div
                            className="flex flex-wrap gap-2"
                            data-test="quick-amounts"
                        >
                            {props.quick_amounts.map((q) => (
                                <Button
                                    key={q.label}
                                    variant="secondary"
                                    size="sm"
                                    className="rounded-full"
                                    onClick={() =>
                                        setLine(lines.length - 1, {
                                            amount: q.amount,
                                            description:
                                                lines[lines.length - 1]
                                                    .description || q.label,
                                        })
                                    }
                                >
                                    {q.label} {q.amount}
                                </Button>
                            ))}
                        </div>
                    </div>
                )}

                <div
                    className="da-card grid gap-1 rounded-[18px] p-4"
                    data-test="charge-totals"
                >
                    {props.taxes.length === 0 && (
                        <p className="text-xs text-muted-foreground">
                            {t('charges.new.no_taxes')}
                        </p>
                    )}
                    <div className="flex justify-between text-base">
                        <span>{t('charges.new.subtotal')}</span>
                        <span>{totals?.subtotal ?? '—'}</span>
                    </div>
                    {(
                        totals?.taxes ??
                        props.taxes.map((x) => ({ ...x, amount: '—' }))
                    ).map((tax) => (
                        <div
                            key={tax.name}
                            className="flex justify-between text-lg font-semibold"
                        >
                            <span>
                                {tax.name} {Number(tax.rate)}%
                            </span>
                            <span>{tax.amount}</span>
                        </div>
                    ))}
                    <div className="mt-1 flex justify-between text-3xl font-bold">
                        <span>{t('charges.new.total')}</span>
                        <span data-test="charge-total">
                            {totals?.total ?? '—'}
                        </span>
                    </div>
                </div>

                {!job && (
                    <div className="da-card rounded-[18px] p-4">
                        <button
                            type="button"
                            className="flex w-full items-center justify-between text-left"
                            aria-expanded={customerOpen}
                            onClick={() => setCustomerOpen(!customerOpen)}
                            data-test="charge-customer-toggle"
                        >
                            <span>
                                <span className="block font-medium">
                                    {t('charges.new.customer')}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {t('charges.new.customer_hint')}
                                </span>
                            </span>
                            <ChevronDown
                                className={customerOpen ? 'rotate-180' : ''}
                            />
                        </button>
                        {customerOpen && (
                            <div className="mt-3 grid gap-2">
                                {found && (
                                    <p
                                        className="rounded-xl bg-green-50 p-2 text-sm text-green-900 dark:bg-green-950 dark:text-green-100"
                                        data-test="charge-customer-found"
                                    >
                                        {t('charges.new.found', {
                                            name: found,
                                        })}
                                    </p>
                                )}
                                <CustomerFields
                                    value={customer}
                                    onChange={setCustomer}
                                    country={country}
                                    onPhoneBlur={lookup}
                                />
                            </div>
                        )}
                    </div>
                )}

                <StickyActionBar>
                    <Button
                        size="lg"
                        disabled={!valid || totals === null}
                        loading={saving}
                        onClick={() => {
                            if (!job) {
                                setPaying(true);

                                return;
                            }
                            setSaving(true);
                            router.post(job.store_url, body, {
                                onFinish: () => setSaving(false),
                            });
                        }}
                        data-test="charge-submit"
                    >
                        {job
                            ? t('charges.new.continue')
                            : `${t('charges.new.charge')} ${totals?.total ?? ''}`}
                    </Button>
                </StickyActionBar>
            </div>
        </>
    );
}
