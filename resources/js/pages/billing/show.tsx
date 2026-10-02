import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Ban,
    CalendarPlus,
    CheckCircle2,
    FilePen,
    CreditCard,
    Pencil,
    Receipt,
    Trash2,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { DocumentStatusBadge } from '@/components/billing/document-status-badge';
import { useMoney } from '@/components/billing/money';
import type { Delivery } from '@/components/billing/document-delivery';
import { DocumentDelivery } from '@/components/billing/document-delivery';
import type { OnlinePayment } from '@/components/billing/online-payment';
import { OnlinePaymentSection } from '@/components/billing/online-payment';
import type { DocumentSms } from '@/components/messaging/types';
import { PaymentDialog } from '@/components/billing/payment-dialog';
import type { BillingDocument, PaymentData } from '@/components/billing/types';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { show as showCustomer } from '@/routes/customers';
import {
    convert,
    decide,
    destroy as destroyEstimate,
    edit as editEstimate,
    revise as reviseRoute,
    show as showEstimate,
} from '@/routes/estimates';
import {
    edit as editInvoice,
    show as showInvoice,
    voidMethod as voidInvoice,
} from '@/routes/invoices';
import { show as showJob } from '@/routes/jobs';
import { voidMethod as voidPayment } from '@/routes/payments';
import type { Option } from '@/types';

type Can = {
    update: boolean;
    revise?: boolean;
    delete?: boolean;
    convert?: boolean;
    recordPayment?: boolean;
    void?: boolean;
    voidPayments?: boolean;
};

export default function BillingShow({
    document: doc,
    can,
    paymentMethods = [],
    today,
    online = null,
    delivery,
    sms = null,
}: {
    document: BillingDocument;
    can: Can;
    paymentMethods?: Option[];
    today: string;
    online?: OnlinePayment;
    delivery?: Delivery;
    sms?: DocumentSms;
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const money = useMoney(doc.currency);
    const time = useCompanyTime();
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [voidOpen, setVoidOpen] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const isInvoice = doc.kind === 'invoice';
    const group = isInvoice ? 'invoices' : 'estimates';
    const balance = doc.balance ?? 0;

    const options = {
        preserveScroll: true,
        onSuccess: () => setError(undefined),
        onError: (errors: Record<string, string>) =>
            setError(Object.values(errors)[0]),
    };

    const setDecision = (approved: boolean) =>
        router.put(decide(doc.id).url, { approved }, options);

    // One tap once the customer approved; otherwise ask first.
    const toInvoice = () => {
        if (
            doc.status === 'approved' ||
            confirm(t('estimates.confirm_convert'))
        ) {
            router.post(convert(doc.id).url, {}, options);
        }
    };

    const revise = () => {
        if (confirm(t('estimates.confirm_revise'))) {
            router.post(reviseRoute(doc.id).url, {}, options);
        }
    };

    const removeEstimate = () => {
        if (confirm(t('estimates.confirm_delete', { number: doc.number }))) {
            router.delete(destroyEstimate(doc.id).url);
        }
    };

    const removePayment = (payment: PaymentData) => {
        if (
            !confirm(
                t('payments.confirm_void', { amount: money(payment.amount) }),
            )
        ) {
            return;
        }

        const reason = prompt(t('payments.void_reason_prompt')) ?? '';
        router.post(voidPayment(payment.id).url, { reason }, options);
    };

    return (
        <>
            <Head title={t(`${group}.number`, { number: doc.number })} />

            <div
                className={
                    can.recordPayment
                        ? 'max-w-3xl space-y-6 p-4 pb-28 md:pb-4'
                        : 'max-w-3xl space-y-6 p-4'
                }
            >
                <PageHeader
                    title={t(`${group}.number`, { number: doc.number })}
                    description={[
                        doc.brand,
                        t('billing.issued', {
                            date: time.dateOnly(doc.issued_on),
                        }),
                        doc.created_by
                            ? t('billing.created_by', { name: doc.created_by })
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        isInvoice
                                            ? editInvoice(doc.id)
                                            : editEstimate(doc.id)
                                    }
                                >
                                    <Pencil /> {t('common.edit')}
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <DocumentStatusBadge
                        status={doc.status}
                        label={doc.status_label}
                    />
                    {doc.valid_until &&
                        (doc.expired ? (
                            <span className="font-medium text-amber-700 dark:text-amber-400">
                                {t('estimates.expired_on', {
                                    date: time.dateOnly(doc.valid_until),
                                })}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                {t('estimates.valid_until_date', {
                                    date: time.dateOnly(doc.valid_until),
                                })}
                            </span>
                        ))}
                    {doc.due_on && doc.status !== 'paid' && (
                        <span className="text-muted-foreground">
                            {t('invoices.due_date', {
                                date: time.dateOnly(doc.due_on),
                            })}
                        </span>
                    )}
                    {doc.approved_at && (
                        <span className="text-muted-foreground">
                            {t('estimates.approved_at', {
                                date: time.date(doc.approved_at),
                            })}
                        </span>
                    )}
                    {doc.declined_at && (
                        <span className="text-muted-foreground">
                            {t('estimates.declined_at', {
                                date: time.date(doc.declined_at),
                            })}
                        </span>
                    )}
                    {doc.invoice && (
                        <Link
                            href={showInvoice(doc.invoice.id)}
                            className="underline"
                        >
                            {t('estimates.invoiced_as', {
                                number: doc.invoice.number,
                            })}
                        </Link>
                    )}
                    {doc.estimate && (
                        <Link
                            href={showEstimate(doc.estimate.id)}
                            className="underline"
                        >
                            {t('invoices.from_estimate', {
                                number: doc.estimate.number,
                            })}
                        </Link>
                    )}
                </div>

                {doc.voided_at && (
                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                        {t('invoices.voided_by', {
                            date: time.date(doc.voided_at),
                            name: doc.voided_by ?? '—',
                        })}
                        {doc.void_reason && ` — ${doc.void_reason}`}
                    </p>
                )}

                <InputError message={error} />

                {/* Customer and job */}
                <section className="grid gap-1 rounded-lg border p-3 text-sm">
                    <Link
                        href={showCustomer(doc.customer.id)}
                        className="font-medium hover:underline"
                    >
                        {doc.customer.display_name}
                    </Link>
                    {doc.address && (
                        <span className="text-muted-foreground">
                            {doc.address}
                        </span>
                    )}
                    {(doc.customer.phone || doc.customer.email) && (
                        <span className="text-muted-foreground">
                            {[phoneText(doc.customer.phone), doc.customer.email]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    )}
                    <Link
                        href={showJob(doc.job.id)}
                        className="w-fit text-muted-foreground underline"
                    >
                        {t('billing.job', { number: doc.job.number })}
                    </Link>
                </section>

                {/* Lines and totals */}
                <section className="space-y-3">
                    <ul className="divide-y rounded-lg border">
                        {doc.items.map((item) => (
                            <li
                                key={item.id}
                                className={
                                    item.optional && !item.selected
                                        ? 'flex items-start gap-3 p-3 text-sm text-muted-foreground'
                                        : 'flex items-start gap-3 p-3 text-sm'
                                }
                            >
                                <div className="min-w-0 flex-1">
                                    {item.optional && (
                                        <p className="text-xs font-medium uppercase">
                                            {item.selected
                                                ? t(
                                                      'estimates.optional_included',
                                                  )
                                                : t(
                                                      'estimates.optional_not_included',
                                                  )}
                                        </p>
                                    )}
                                    {(item.kind !== 'service' ||
                                        !item.bill_to_customer) && (
                                        <p className="text-xs font-medium text-muted-foreground uppercase">
                                            {[
                                                t(`billing.kinds.${item.kind}`),
                                                item.part_number,
                                                item.bill_to_customer
                                                    ? null
                                                    : t(
                                                          'billing.line.internal',
                                                      ),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    )}
                                    <p className="whitespace-pre-line">
                                        {item.description}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('billing.qty_times_price', {
                                            quantity: item.unit
                                                ? `${Number(item.quantity)} ${item.unit}`
                                                : Number(item.quantity),
                                            price: money(item.unit_price),
                                        })}
                                        {!item.taxable &&
                                            ` · ${t('billing.not_taxable')}`}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {item.warranty_ends_on
                                            ? t('billing.warranty_until', {
                                                  length: item.warranty_label,
                                                  date: time.dateOnly(
                                                      item.warranty_ends_on,
                                                  ),
                                              })
                                            : item.warranty_value
                                              ? t('billing.warranty_line', {
                                                    length: item.warranty_label,
                                                })
                                              : item.warranty_label}
                                        {item.total_cost !== null &&
                                            item.total_cost > 0 &&
                                            ` · ${t('billing.line.total_cost')} ${money(item.total_cost)}${item.supplier ? ` (${item.supplier})` : ''}`}
                                    </p>
                                </div>
                                <span
                                    className={
                                        item.optional && !item.selected
                                            ? 'tabular-nums line-through'
                                            : 'font-medium tabular-nums'
                                    }
                                >
                                    {money(item.total)}
                                </span>
                            </li>
                        ))}
                    </ul>

                    <dl className="ml-auto max-w-xs space-y-1 text-sm">
                        <div className="flex justify-between">
                            <dt>{t('billing.subtotal')}</dt>
                            <dd className="tabular-nums">
                                {money(doc.subtotal)}
                            </dd>
                        </div>
                        {doc.discount_total > 0 && (
                            <div className="flex justify-between">
                                <dt>
                                    {doc.discount_type === 'percent'
                                        ? t('billing.discount_percent', {
                                              value: Number(doc.discount_value),
                                          })
                                        : t('billing.discount')}
                                </dt>
                                <dd className="tabular-nums">
                                    −{money(doc.discount_total)}
                                </dd>
                            </div>
                        )}
                        {doc.taxes.map((tax) => (
                            <div
                                key={`${tax.tax_rate_id}-${tax.name}`}
                                className="flex justify-between"
                            >
                                <dt>
                                    {t(
                                        doc.prices_include_tax
                                            ? 'billing.includes_tax'
                                            : 'billing.tax_line',
                                        { name: tax.name, rate: tax.rate },
                                    )}
                                </dt>
                                <dd className="tabular-nums">
                                    {money(tax.amount)}
                                </dd>
                            </div>
                        ))}
                        <div className="flex justify-between border-t pt-1 text-base font-semibold">
                            <dt>{t('billing.total')}</dt>
                            <dd className="tabular-nums">{money(doc.total)}</dd>
                        </div>
                        {doc.cost_total !== null &&
                            doc.cost_total !== undefined &&
                            doc.cost_total > 0 && (
                                <div className="flex justify-between text-muted-foreground">
                                    <dt>{t('billing.cost_total')}</dt>
                                    <dd className="tabular-nums">
                                        {money(doc.cost_total)}
                                    </dd>
                                </div>
                            )}
                        {isInvoice && doc.status !== 'void' && (
                            <>
                                <div className="flex justify-between">
                                    <dt>{t('invoices.paid')}</dt>
                                    <dd className="tabular-nums">
                                        {money(doc.amount_paid ?? 0)}
                                    </dd>
                                </div>
                                <div className="flex justify-between text-base font-semibold">
                                    <dt>{t('invoices.balance')}</dt>
                                    <dd className="tabular-nums">
                                        {money(balance)}
                                    </dd>
                                </div>
                            </>
                        )}
                        {!isInvoice && (doc.deposit_amount ?? 0) > 0 && (
                            <div className="flex justify-between">
                                <dt>
                                    {doc.deposit_type === 'percent'
                                        ? t('estimates.deposit_percent', {
                                              percent: Number(
                                                  doc.deposit_value,
                                              ),
                                          })
                                        : t('estimates.deposit')}
                                </dt>
                                <dd className="tabular-nums">
                                    {money(doc.deposit_amount ?? 0)}
                                </dd>
                            </div>
                        )}
                        {!isInvoice && (doc.deposit_paid ?? 0) !== 0 && (
                            <div className="flex justify-between">
                                <dt>{t('estimates.deposit_paid')}</dt>
                                <dd className="tabular-nums">
                                    {money(doc.deposit_paid ?? 0)}
                                </dd>
                            </div>
                        )}
                    </dl>

                    {doc.notes && (
                        <p className="rounded-lg bg-muted/50 p-3 text-sm whitespace-pre-line">
                            {doc.notes}
                        </p>
                    )}
                </section>

                {doc.online_approval && (
                    <section className="space-y-2 rounded-lg border border-green-600/40 bg-green-50 p-3 text-sm dark:bg-green-950">
                        {doc.online_approval.signature ? (
                            <img
                                src={doc.online_approval.signature}
                                alt={t('estimates.online.signature')}
                                className="h-20 max-w-64 rounded border bg-white object-contain"
                            />
                        ) : (
                            <p className="font-serif text-2xl italic">
                                {doc.online_approval.signer_name}
                            </p>
                        )}
                        <p>
                            {t('estimates.online.signed_online', {
                                name: doc.online_approval.signer_name,
                                date: doc.approved_at
                                    ? time.dateTime(doc.approved_at)
                                    : '',
                            })}
                            {doc.online_approval.ip &&
                                ` · ${t('estimates.online.ip', { ip: doc.online_approval.ip })}`}
                        </p>
                        {doc.status !== 'invoiced' &&
                            doc.status !== 'revised' && (
                                <p className="text-xs text-muted-foreground">
                                    {t('estimates.locked_signed')}
                                </p>
                            )}
                        {can.revise && (
                            <Button
                                variant="outline"
                                className="h-11 w-full bg-background sm:w-auto"
                                onClick={revise}
                            >
                                <FilePen /> {t('estimates.revise')}
                            </Button>
                        )}
                    </section>
                )}
                {doc.revised_from && !delivery?.sent_at && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-50 p-3 text-sm dark:bg-amber-950">
                        {t('estimates.send_revision', {
                            number: doc.revised_from,
                        })}
                    </p>
                )}
                {(doc.versions ?? []).length > 1 && (
                    <section className="space-y-2">
                        <h2 className="text-base font-medium">
                            {t('estimates.versions')}
                        </h2>
                        <ul className="divide-y rounded-lg border text-sm">
                            {(doc.versions ?? []).map((v) => (
                                <li key={v.id}>
                                    <Link
                                        href={showEstimate(v.id)}
                                        className={
                                            v.id === doc.id
                                                ? 'flex items-center gap-2 bg-muted/50 p-3'
                                                : 'flex items-center gap-2 p-3 hover:bg-muted/50'
                                        }
                                    >
                                        <span className="font-medium">
                                            {v.number}
                                        </span>
                                        <DocumentStatusBadge
                                            status={v.status}
                                            label={v.status_label}
                                        />
                                        <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                                            {[
                                                v.signer_name && v.approved_at
                                                    ? t(
                                                          'estimates.online.signed_online',
                                                          {
                                                              name: v.signer_name,
                                                              date: time.dateTime(
                                                                  v.approved_at,
                                                              ),
                                                          },
                                                      )
                                                    : null,
                                                v.revised_at
                                                    ? t(
                                                          'estimates.revised_on',
                                                          {
                                                              date: time.date(
                                                                  v.revised_at,
                                                              ),
                                                          },
                                                      )
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                        <span className="tabular-nums">
                                            {money(v.total)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {doc.status === 'declined' && doc.decline_reason && (
                    <p className="rounded-lg bg-muted p-3 text-sm whitespace-pre-line">
                        {t('estimates.online.decline_reason_label', {
                            reason: doc.decline_reason,
                        })}
                    </p>
                )}

                {delivery && (
                    <DocumentDelivery
                        delivery={delivery}
                        kindLabel={t(
                            isInvoice
                                ? 'documents.invoice'
                                : 'documents.estimate',
                        )}
                        number={doc.number}
                        sms={sms}
                    />
                )}

                {isInvoice && online && balance > 0 && (
                    <OnlinePaymentSection
                        invoiceId={doc.id}
                        online={online}
                        balance={balance}
                        currency={doc.currency}
                    />
                )}

                {/* Estimate actions */}
                {!isInvoice && doc.status === 'approved' && can.convert && (
                    <section className="grid gap-2 sm:grid-cols-2">
                        <Button className="h-12 text-base" onClick={toInvoice}>
                            <Receipt /> {t('estimates.convert_approved')}
                        </Button>
                        <Button variant="outline" className="h-12" asChild>
                            <Link href={showJob(doc.job.id)}>
                                <CalendarPlus /> {t('estimates.schedule_visit')}
                            </Link>
                        </Button>
                    </section>
                )}
                {!isInvoice && can.update && (
                    <section className="grid gap-2 sm:grid-cols-2">
                        {doc.status !== 'approved' && (
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => setDecision(true)}
                            >
                                <CheckCircle2 /> {t('estimates.approve')}
                            </Button>
                        )}
                        {doc.status !== 'declined' && (
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => setDecision(false)}
                            >
                                <XCircle /> {t('estimates.decline')}
                            </Button>
                        )}
                        {can.convert && doc.status !== 'approved' && (
                            <Button
                                className="h-11 sm:col-span-2"
                                onClick={toInvoice}
                            >
                                <Receipt /> {t('estimates.convert')}
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="ghost"
                                className="h-11 text-destructive sm:col-span-2"
                                onClick={removeEstimate}
                            >
                                <Trash2 /> {t('common.delete')}
                            </Button>
                        )}
                    </section>
                )}

                {/* Payments */}
                {isInvoice && (
                    <section className="space-y-2">
                        <h2 className="text-base font-medium">
                            {t('payments.title')}
                        </h2>
                        {(doc.payments ?? []).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('payments.empty')}
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {(doc.payments ?? []).map((p) => (
                                    <li
                                        key={p.id}
                                        className={
                                            p.voided_at
                                                ? 'flex items-start gap-3 p-3 text-sm text-muted-foreground'
                                                : 'flex items-start gap-3 p-3 text-sm'
                                        }
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2 font-medium">
                                                <span
                                                    className={
                                                        p.voided_at
                                                            ? 'line-through'
                                                            : undefined
                                                    }
                                                >
                                                    {p.is_refund
                                                        ? t('payments.refund')
                                                        : p.provider
                                                          ? t(
                                                                'payments.online',
                                                                {
                                                                    provider:
                                                                        p.provider,
                                                                },
                                                            )
                                                          : p.method_label}
                                                </span>
                                                {p.voided_at && (
                                                    <span className="text-xs">
                                                        {t(
                                                            'payments.voided_label',
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {[
                                                    p.received_at
                                                        ? time.date(
                                                              p.received_at,
                                                          )
                                                        : null,
                                                    p.reference,
                                                    p.tip_amount !== 0
                                                        ? t('payments.tip', {
                                                              amount: money(
                                                                  p.tip_amount,
                                                              ),
                                                          })
                                                        : null,
                                                    p.user
                                                        ? t('payments.by', {
                                                              name: p.user,
                                                          })
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                            {p.note && (
                                                <p className="text-xs whitespace-pre-line">
                                                    {p.note}
                                                </p>
                                            )}
                                            {p.void_reason && (
                                                <p className="text-xs">
                                                    {p.void_reason}
                                                </p>
                                            )}
                                        </div>
                                        <span
                                            className={
                                                p.voided_at
                                                    ? 'tabular-nums line-through'
                                                    : 'font-medium tabular-nums'
                                            }
                                        >
                                            {money(p.amount)}
                                        </span>
                                        {can.voidPayments &&
                                            !p.voided_at &&
                                            !p.provider && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-9"
                                                    aria-label={t(
                                                        'payments.void',
                                                    )}
                                                    onClick={() =>
                                                        removePayment(p)
                                                    }
                                                >
                                                    <Ban />
                                                </Button>
                                            )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {can.void && (
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                onClick={() => setVoidOpen(true)}
                            >
                                <Ban /> {t('invoices.void')}
                            </Button>
                        )}
                    </section>
                )}

                {can.recordPayment && (
                    <div className="fixed inset-x-0 bottom-0 z-20 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:static md:border-0 md:bg-transparent md:p-0 md:shadow-none">
                        <Button
                            className="h-12 w-full text-base md:w-auto"
                            onClick={() => setPaymentOpen(true)}
                        >
                            <CreditCard /> {t('payments.record')} ·{' '}
                            {money(balance)}
                        </Button>
                    </div>
                )}
            </div>

            {can.recordPayment && (
                <PaymentDialog
                    open={paymentOpen}
                    onOpenChange={setPaymentOpen}
                    invoiceId={doc.id}
                    balance={balance}
                    currency={doc.currency}
                    methods={paymentMethods}
                    today={today}
                />
            )}
            {can.void && (
                <VoidInvoiceDialog
                    open={voidOpen}
                    onOpenChange={setVoidOpen}
                    invoiceId={doc.id}
                />
            )}
        </>
    );
}

function VoidInvoiceDialog({
    open,
    onOpenChange,
    invoiceId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceId: number;
}) {
    const t = useTrans();
    const form = useForm({ reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(voidInvoice(invoiceId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('invoices.void')}</DialogTitle>
                    <DialogDescription>
                        {t('invoices.void_description')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <FormField
                        id="void-reason"
                        label={t('invoices.void_reason')}
                        error={
                            form.errors.reason ??
                            (form.errors as Record<string, string>).invoice
                        }
                    >
                        <Textarea
                            id="void-reason"
                            rows={2}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                    </FormField>
                    <Button
                        type="submit"
                        variant="destructive"
                        className="w-full"
                        disabled={form.processing}
                    >
                        {t('invoices.void')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
