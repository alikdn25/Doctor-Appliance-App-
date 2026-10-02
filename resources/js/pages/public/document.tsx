import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CreditCard,
    Download,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { EstimateActions } from '@/components/billing/estimate-approval';
import {
    ApproveDialog,
    DeclineDialog,
    estimateTotals,
} from '@/components/billing/estimate-approval';
import { formatMoney } from '@/components/billing/money';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useTrans } from '@/lib/i18n';
import {
    deposit as depositRoute,
    pay as payRoute,
    pdf as pdfRoute,
} from '@/routes/documents/public';

type PrintedDocument = {
    kind: 'estimate' | 'invoice';
    title: string;
    number: string;
    status: string;
    status_label: string;
    issued_on: string | null;
    due_on: string | null;
    valid_until: string | null;
    brand: {
        name: string | null;
        fallback_name: string;
        color: string;
        logo: string | null;
        address: string | null;
        phone: string | null;
        email: string | null;
        website: string | null;
        tax_number: string | null;
    };
    customer: {
        name: string | null;
        address: string | null;
    };
    items: {
        id: number;
        description: string;
        quantity: string;
        unit_price: string;
        total: string;
        taxable: boolean;
        optional: boolean;
        included: boolean;
        unit: string | null;
        part_number: string | null;
        warranty: string | null;
        warranty_until: string | null;
    }[];
    warranty_terms: string | null;
    subtotal: string;
    discount: string | null;
    taxes: { label: string; amount: string }[];
    prices_include_tax: boolean;
    total: string;
    amount_paid: string | null;
    balance: string | null;
    balance_minor: number | null;
    notes: string | null;
    terms: string | null;
    footer: string | null;
    approval: {
        approved_at: string | null;
        declined_at: string | null;
        online: boolean;
        signer_name: string | null;
        signature_type: 'drawn' | 'typed' | null;
        signature: string | null;
        decline_reason: string | null;
        expired: boolean;
        deposit: string | null;
        deposit_percent: string | null;
        deposit_paid: string | null;
        deposit_due: string | null;
        deposit_due_minor: number;
    } | null;
};

/**
 * The customer's online estimate / invoice (no login). Mobile first: the pay button is the main action.
 */
export default function PublicDocument({
    token,
    document: doc,
    canPay,
    estimate = null,
}: {
    token: string;
    document: PrintedDocument;
    canPay: boolean;
    estimate?: EstimateActions | null;
}) {
    const t = useTrans();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [paying, setPaying] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [declineOpen, setDeclineOpen] = useState(false);
    const brandName = doc.brand.name ?? doc.brand.fallback_name;
    const due = (doc.balance_minor ?? 0) > 0;
    const approval = doc.approval;

    // Optional lines the customer ticks; the totals follow live while they can still approve.
    const [selected, setSelected] = useState<number[]>(
        () =>
            estimate?.calc.items
                .filter((item) => item.optional && item.selected)
                .map((item) => item.id) ?? [],
    );
    const choosing = estimate?.can_approve ?? false;
    const live =
        estimate && choosing ? estimateTotals(estimate.calc, selected) : null;
    const money = (minor: number) =>
        estimate
            ? formatMoney(minor, estimate.calc.currency, estimate.calc.locale)
            : '';
    const subtotal = live ? money(live.subtotal) : doc.subtotal;
    const discount = live
        ? live.discount > 0
            ? money(-live.discount)
            : null
        : doc.discount;
    const taxes = live
        ? live.taxes.map((tax) => ({
              label: t(
                  doc.prices_include_tax
                      ? 'billing.includes_tax'
                      : 'billing.tax_line',
                  { name: tax.name, rate: tax.rate },
              ),
              amount: money(tax.amount),
          }))
        : doc.taxes;
    const total = live ? money(live.total) : doc.total;
    const deposit = live
        ? live.deposit > 0
            ? money(live.deposit)
            : null
        : (approval?.deposit ?? null);

    const toggle = (id: number, on: boolean) =>
        setSelected((current) =>
            on ? [...current, id] : current.filter((x) => x !== id),
        );

    const payDeposit = () =>
        router.post(
            depositRoute(token).url,
            {},
            {
                onStart: () => setPaying(true),
                onFinish: () => setPaying(false),
            },
        );

    const pay = () =>
        router.post(
            payRoute(token).url,
            {},
            {
                onStart: () => setPaying(true),
                onFinish: () => setPaying(false),
            },
        );

    return (
        <>
            <Head title={`${doc.title} ${doc.number} · ${brandName}`} />

            <div className="min-h-screen bg-muted/40 py-6">
                <main className="mx-auto max-w-2xl space-y-4 bg-background p-5 shadow-sm sm:rounded-lg">
                    <div
                        className="-mx-5 -mt-5 h-1.5 sm:rounded-t-lg"
                        style={{ backgroundColor: doc.brand.color }}
                    />

                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div className="text-sm">
                            {doc.brand.logo && (
                                <img
                                    src={doc.brand.logo}
                                    alt={brandName}
                                    className="mb-2 max-h-14 max-w-48"
                                />
                            )}
                            <p className="text-base font-semibold">
                                {brandName}
                            </p>
                            {[
                                doc.brand.address,
                                doc.brand.phone,
                                doc.brand.email,
                                doc.brand.website,
                            ]
                                .filter(Boolean)
                                .map((line) => (
                                    <p
                                        key={line}
                                        className="text-muted-foreground"
                                    >
                                        {line}
                                    </p>
                                ))}
                        </div>
                        <div className="text-sm sm:text-right">
                            <p
                                className="text-2xl font-bold"
                                style={{ color: doc.brand.color }}
                            >
                                {doc.title}
                            </p>
                            <p>{doc.number}</p>
                            <p className="text-muted-foreground">
                                {doc.issued_on}
                            </p>
                            {doc.due_on && (
                                <p className="text-muted-foreground">
                                    {t('documents.due')}: {doc.due_on}
                                </p>
                            )}
                            {doc.valid_until && (
                                <p className="text-muted-foreground">
                                    {t('documents.valid_until')}:{' '}
                                    {doc.valid_until}
                                </p>
                            )}
                        </div>
                    </header>

                    <section className="text-sm">
                        <p className="text-xs text-muted-foreground uppercase">
                            {t('documents.bill_to')}
                        </p>
                        <p className="font-medium">{doc.customer.name}</p>
                        {doc.customer.address && <p>{doc.customer.address}</p>}
                    </section>

                    {choosing && doc.items.some((item) => item.optional) && (
                        <p className="text-sm text-muted-foreground">
                            {t('estimates.online.choose_hint')}
                        </p>
                    )}
                    <ul className="divide-y rounded-lg border text-sm">
                        {doc.items.map((item, i) => (
                            <li
                                key={i}
                                className={
                                    item.optional &&
                                    !(choosing
                                        ? selected.includes(item.id)
                                        : item.included)
                                        ? 'flex gap-3 p-3 text-muted-foreground'
                                        : 'flex gap-3 p-3'
                                }
                            >
                                {item.optional && choosing && (
                                    <Checkbox
                                        className="mt-0.5 size-6"
                                        aria-label={item.description}
                                        checked={selected.includes(item.id)}
                                        onCheckedChange={(c) =>
                                            toggle(item.id, c === true)
                                        }
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    {item.optional && (
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
                                            {choosing
                                                ? t('estimates.optional')
                                                : item.included
                                                  ? t(
                                                        'estimates.optional_included',
                                                    )
                                                  : t(
                                                        'estimates.optional_not_included',
                                                    )}
                                        </p>
                                    )}
                                    <p className="whitespace-pre-line">
                                        {item.description}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('billing.qty_times_price', {
                                            quantity: item.unit
                                                ? `${item.quantity} ${item.unit}`
                                                : item.quantity,
                                            price: item.unit_price,
                                        })}
                                    </p>
                                    {item.warranty && (
                                        <p className="text-xs text-muted-foreground">
                                            {item.warranty_until
                                                ? t('billing.warranty_until', {
                                                      length: item.warranty,
                                                      date: item.warranty_until,
                                                  })
                                                : t('billing.warranty_line', {
                                                      length: item.warranty,
                                                  })}
                                        </p>
                                    )}
                                </div>
                                <span className="tabular-nums">
                                    {item.total}
                                </span>
                            </li>
                        ))}
                    </ul>

                    <dl className="ml-auto max-w-xs space-y-1 text-sm">
                        <Row label={t('billing.subtotal')} value={subtotal} />
                        {discount && (
                            <Row
                                label={t('billing.discount')}
                                value={discount}
                            />
                        )}
                        {taxes.map((tax) => (
                            <Row
                                key={tax.label}
                                label={tax.label}
                                value={tax.amount}
                            />
                        ))}
                        <Row bold label={t('billing.total')} value={total} />
                        {doc.prices_include_tax && (
                            <p className="text-right text-xs text-muted-foreground">
                                {t('billing.prices_include_tax')}
                            </p>
                        )}
                        {doc.amount_paid && (
                            <Row
                                label={t('invoices.paid')}
                                value={doc.amount_paid}
                            />
                        )}
                        {doc.balance !== null && (
                            <Row
                                bold
                                label={t('invoices.balance')}
                                value={doc.balance}
                            />
                        )}
                        {deposit && (
                            <Row
                                label={
                                    approval?.deposit_percent
                                        ? t('estimates.deposit_percent', {
                                              percent: approval.deposit_percent,
                                          })
                                        : t('estimates.deposit')
                                }
                                value={deposit}
                            />
                        )}
                        {approval?.deposit_paid && (
                            <Row
                                label={t('estimates.deposit_paid')}
                                value={approval.deposit_paid}
                            />
                        )}
                    </dl>

                    {estimate && approval && (
                        <EstimateStatus
                            status={doc.status}
                            approval={approval}
                            actions={estimate}
                            deposit={deposit}
                            validUntil={doc.valid_until}
                        />
                    )}
                    {estimate?.can_pay_deposit && approval?.deposit_due && (
                        <Button
                            className="h-12 w-full text-base"
                            style={{ backgroundColor: doc.brand.color }}
                            disabled={paying}
                            onClick={payDeposit}
                        >
                            <CreditCard />
                            {t('estimates.online.pay_deposit', {
                                amount: approval.deposit_due,
                            })}
                        </Button>
                    )}
                    {estimate &&
                        (estimate.can_approve || estimate.can_decline) && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {estimate.can_approve && (
                                    <Button
                                        className="h-12 text-base"
                                        style={{
                                            backgroundColor: doc.brand.color,
                                        }}
                                        onClick={() => setApproveOpen(true)}
                                    >
                                        <CheckCircle2 />
                                        {t('estimates.online.approve')}
                                    </Button>
                                )}
                                {estimate.can_decline && (
                                    <Button
                                        variant="outline"
                                        className="h-12 text-base"
                                        onClick={() => setDeclineOpen(true)}
                                    >
                                        <XCircle />
                                        {t('estimates.online.decline')}
                                    </Button>
                                )}
                            </div>
                        )}

                    {doc.kind === 'invoice' && doc.status === 'void' && (
                        <p className="rounded-md bg-muted p-3 text-sm">
                            {t('documents.public.void')}
                        </p>
                    )}
                    {doc.kind === 'invoice' &&
                        doc.status !== 'void' &&
                        !due && (
                            <p className="rounded-md bg-green-50 p-3 text-sm text-green-800 dark:bg-green-950 dark:text-green-200">
                                {t('documents.public.paid')}
                            </p>
                        )}
                    {canPay && (
                        <Button
                            className="h-12 w-full text-base"
                            style={{ backgroundColor: doc.brand.color }}
                            disabled={paying}
                            onClick={pay}
                        >
                            <CreditCard />
                            {t('documents.public.pay', {
                                amount: doc.balance ?? '',
                            })}
                        </Button>
                    )}
                    {errors?.pay && (
                        <p className="text-sm text-destructive">{errors.pay}</p>
                    )}

                    <Button variant="outline" className="h-11 w-full" asChild>
                        <a
                            href={pdfRoute(token).url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Download /> {t('documents.public.download')}
                        </a>
                    </Button>

                    {doc.notes && (
                        <p className="rounded-md bg-muted/50 p-3 text-sm whitespace-pre-line">
                            {doc.notes}
                        </p>
                    )}
                    {doc.warranty_terms &&
                        doc.items.some((item) => item.warranty) && (
                            <div className="text-sm">
                                <p className="font-medium">
                                    {t('billing.warranty_terms')}
                                </p>
                                <p className="whitespace-pre-line text-muted-foreground">
                                    {doc.warranty_terms}
                                </p>
                            </div>
                        )}
                    {doc.terms && (
                        <div className="text-sm">
                            <p className="font-medium">
                                {t('documents.terms')}
                            </p>
                            <p className="whitespace-pre-line text-muted-foreground">
                                {doc.terms}
                            </p>
                        </div>
                    )}
                    {doc.brand.tax_number && (
                        <p className="text-xs text-muted-foreground">
                            {t('documents.tax_number', {
                                number: doc.brand.tax_number,
                            })}
                        </p>
                    )}
                    {doc.footer && (
                        <p className="border-t pt-3 text-center text-xs text-muted-foreground">
                            {doc.footer}
                        </p>
                    )}
                </main>
            </div>

            {estimate?.can_approve && (
                <ApproveDialog
                    open={approveOpen}
                    onOpenChange={setApproveOpen}
                    token={token}
                    actions={estimate}
                    selected={selected}
                    total={total}
                    deposit={deposit}
                    color={doc.brand.color}
                />
            )}
            {estimate?.can_decline && (
                <DeclineDialog
                    open={declineOpen}
                    onOpenChange={setDeclineOpen}
                    token={token}
                />
            )}
        </>
    );
}

/**
 * Where the estimate stands for the customer: approved (with their signature and the deposit), declined, expired.
 */
function EstimateStatus({
    status,
    approval,
    actions,
    deposit,
    validUntil,
}: {
    status: string;
    approval: NonNullable<PrintedDocument['approval']>;
    actions: EstimateActions;
    deposit: string | null;
    validUntil: string | null;
}) {
    const t = useTrans();

    if (status === 'revised') {
        return (
            <div className="space-y-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                <p>{t('estimates.online.revised')}</p>
                {actions.latest_url && (
                    <a
                        href={actions.latest_url}
                        className="font-medium underline"
                    >
                        {t('estimates.online.view_latest')}
                    </a>
                )}
            </div>
        );
    }

    if (status === 'invoiced') {
        return (
            <p className="rounded-md bg-muted p-3 text-sm">
                {t('estimates.online.invoiced')}
            </p>
        );
    }

    if (status === 'approved') {
        return (
            <div className="space-y-2 rounded-md bg-green-50 p-3 text-sm text-green-900 dark:bg-green-950 dark:text-green-100">
                <p className="flex items-center gap-2 font-medium">
                    <CheckCircle2 className="size-4" />
                    {t('estimates.online.approved_notice', {
                        date: approval.approved_at ?? '',
                    })}
                </p>
                {approval.online && <Signature approval={approval} />}
                {approval.deposit_paid && (
                    <p>
                        {t('estimates.online.deposit_received', {
                            amount: approval.deposit_paid,
                        })}
                    </p>
                )}
                {approval.deposit_due_minor > 0 && !actions.online_payments && (
                    <p>
                        {t('estimates.online.deposit_contact', {
                            amount: approval.deposit_due ?? '',
                        })}
                    </p>
                )}
            </div>
        );
    }

    if (approval.expired && !actions.can_approve) {
        return (
            <p className="flex items-start gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                {t('estimates.online.expired', { date: validUntil ?? '' })}
            </p>
        );
    }

    if (status === 'declined') {
        return (
            <div className="space-y-1 rounded-md bg-muted p-3 text-sm">
                <p>
                    {t('estimates.online.declined_notice', {
                        date: approval.declined_at ?? '',
                    })}
                </p>
                {approval.decline_reason && (
                    <p className="text-muted-foreground">
                        {t('estimates.online.decline_reason_label', {
                            reason: approval.decline_reason,
                        })}
                    </p>
                )}
                {actions.can_approve && (
                    <p>{t('estimates.online.reconsider')}</p>
                )}
            </div>
        );
    }

    return deposit && actions.can_approve ? (
        <p className="rounded-md bg-muted/50 p-3 text-sm">
            {t('estimates.online.deposit_asked', { amount: deposit })}
        </p>
    ) : null;
}

function Signature({
    approval,
}: {
    approval: NonNullable<PrintedDocument['approval']>;
}) {
    const t = useTrans();

    return (
        <div className="space-y-1">
            {approval.signature ? (
                <img
                    src={approval.signature}
                    alt={t('estimates.online.signature')}
                    className="h-20 max-w-64 rounded border bg-white object-contain"
                />
            ) : (
                <p className="font-serif text-2xl italic">
                    {approval.signer_name}
                </p>
            )}
            <p className="text-xs">
                {t('estimates.online.signed_by', {
                    name: approval.signer_name ?? '',
                    date: approval.approved_at ?? '',
                })}
            </p>
        </div>
    );
}

function Row({
    label,
    value,
    bold = false,
}: {
    label: string;
    value: string;
    bold?: boolean;
}) {
    return (
        <div
            className={
                bold
                    ? 'flex justify-between border-t pt-1 text-base font-semibold'
                    : 'flex justify-between'
            }
        >
            <dt>{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}
