import { Head, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TaxIncludedSwitch } from '@/components/billing/tax-included-switch';
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
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { hasCardReader } from '@/lib/card-reader';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Line = { description: string; amount: string; tax_rate_ids: number[] };

type Totals = {
    subtotal: string;
    taxes: { id: number; name: string; rate: string; amount: string }[];
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
 * New charge ("+" → Charge reader) and the quick invoice of a job without one (owners' tasks of November 21 and 22):
 * a list of lines (description, by voice through the keyboard's microphone, and amount), each with a large tick per
 * company tax (only the first is ticked on a new line); taxes added on top, or worked out of the amounts with
 * "Price includes tax" (off on every new screen); quick amounts from the price
 * list; Subtotal, each tax used by a line and the Total (worked out on the server); and the customer (collapsed,
 * phone first; a phone already in the database fills in that customer). Empty lines are left out. Charge opens Take
 * payment; the customer, a Completed job and the paid invoice are made only after the payment.
 */
export default function NewCharge(props: Props) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country;
    const { job } = props;
    const newLine = (description = ''): Line => ({
        description,
        amount: '',
        tax_rate_ids: [...props.default_tax_ids],
    });
    const [lines, setLines] = useState<Line[]>([
        newLine(job?.description ?? ''),
    ]);
    const [focusLine, setFocusLine] = useState<number | null>(null);
    const descriptions = useRef<(HTMLInputElement | null)[]>([]);
    // "Price includes tax": off on every new screen (never remembered).
    const [taxIncluded, setTaxIncluded] = useState(props.price_includes_tax);
    const [totals, setTotals] = useState<Totals | null>(null);
    const [customerOpen, setCustomerOpen] = useState(false);
    const [customer, setCustomer] = useState<CustomerDetails>(emptyCustomer);
    const [found, setFound] = useState<string | null>(null);
    const [paying, setPaying] = useState(false);
    const [saving, setSaving] = useState(false);
    const lookedUp = useRef('');

    // Empty lines are left out; a line with a description needs an amount; at least one amount.
    const filled = lines.filter(
        (l) => l.description.trim() !== '' || l.amount.trim() !== '',
    );
    const valid =
        filled.length > 0 && filled.every((l) => Number(l.amount) > 0);
    const body = {
        lines: filled,
        amounts_include_tax: taxIncluded,
    };

    // "+ Add line": the cursor goes straight to the new line's description.
    useEffect(() => {
        if (focusLine === null) return;
        descriptions.current[focusLine]?.focus();
        setFocusLine(null);
    }, [focusLine]);

    const addLine = () => {
        setLines([...lines, newLine()]);
        setFocusLine(lines.length);
    };

    const toggleTax = (index: number, id: number) => {
        const ids = lines[index].tax_rate_ids;
        setLine(index, {
            tax_rate_ids: ids.includes(id)
                ? ids.filter((x) => x !== id)
                : [...ids, id],
        });
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
                        className="da-card relative grid gap-3 rounded-[18px] p-4"
                        data-test="charge-line"
                    >
                        {lines.length > 1 && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="absolute top-2 right-2 text-danger"
                                aria-label={t('charges.new.remove_line')}
                                onClick={() =>
                                    setLines(
                                        lines.filter((_, i) => i !== index),
                                    )
                                }
                                data-test="charge-remove-line"
                            >
                                <Trash2 />
                            </Button>
                        )}
                        <label className="grid gap-1 text-sm">
                            <span
                                className={
                                    lines.length > 1
                                        ? 'pr-10 font-medium'
                                        : 'font-medium'
                                }
                            >
                                {t('charges.new.description')}
                            </span>
                            <Input
                                ref={(el) => {
                                    descriptions.current[index] = el;
                                }}
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
                        <label className="grid gap-1 text-sm">
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
                        {props.taxes.length > 0 && (
                            <div
                                className="flex flex-wrap gap-2"
                                role="group"
                                aria-label={t('charges.new.line_taxes')}
                            >
                                {props.taxes.map((tax) => {
                                    const on = line.tax_rate_ids.includes(
                                        tax.id,
                                    );

                                    return (
                                        <button
                                            key={tax.id}
                                            type="button"
                                            role="checkbox"
                                            aria-checked={on}
                                            onClick={() =>
                                                toggleTax(index, tax.id)
                                            }
                                            className={cn(
                                                'da-chip da-press inline-flex min-h-12 min-w-[7.5rem] flex-1 items-center gap-2.5 rounded-2xl px-3 text-base font-semibold',
                                                on &&
                                                    'text-primary ring-2 ring-primary',
                                            )}
                                            data-test={`charge-tax-${tax.id}`}
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-7 shrink-0 items-center justify-center rounded-lg border-2',
                                                    on
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-transparent',
                                                )}
                                            >
                                                {on && (
                                                    <Check className="size-5" />
                                                )}
                                            </span>
                                            {tax.name} {tax.rate}%
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                ))}

                <Button
                    variant="tertiary"
                    onClick={addLine}
                    data-test="charge-add-line"
                >
                    <Plus /> {t('charges.new.add_line')}
                </Button>

                <TaxIncludedSwitch
                    checked={taxIncluded}
                    onChange={setTaxIncluded}
                />

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
                        <span data-test="charge-subtotal">
                            {totals?.subtotal ?? '—'}
                        </span>
                    </div>
                    {/* Only the taxes ticked on at least one line. */}
                    {(
                        totals?.taxes ??
                        props.taxes
                            .filter((x) =>
                                lines.some((l) =>
                                    l.tax_rate_ids.includes(x.id),
                                ),
                            )
                            .map((x) => ({ ...x, amount: '—' }))
                    ).map((tax) => (
                        <div
                            key={tax.id}
                            className="flex justify-between text-lg font-semibold"
                            data-test={`charge-total-tax-${tax.id}`}
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
                            ? `${t('charges.new.continue')} ${totals?.total ?? ''}`
                            : `${t('charges.new.charge')} ${totals?.total ?? ''}`}
                    </Button>
                </StickyActionBar>
            </div>
        </>
    );
}
