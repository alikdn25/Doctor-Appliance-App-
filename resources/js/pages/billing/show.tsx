import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    CreditCard,
    Download,
    FilePen,
    Pencil,
    Receipt,
    Stethoscope,
    Trash2,
    Undo2,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    DeliveryStatus,
    SendMenu,
    useDocumentDelivery,
} from '@/components/billing/document-delivery';
import type { Delivery } from '@/components/billing/document-delivery';
import { DocumentStatusBadge } from '@/components/billing/document-status-badge';
import { fromMinor, useMoney } from '@/components/billing/money';
import type { OnlinePayment } from '@/components/billing/online-payment';
import { OnlinePaymentSection } from '@/components/billing/online-payment';
import { PaymentDialog } from '@/components/billing/payment-dialog';
import type { BillingDocument, PaymentData } from '@/components/billing/types';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { SignatureSection } from '@/components/jobs/signature-section';
import type { SignatureData } from '@/components/jobs/signature-section';
import type { DocumentSms, ReviewPrompt } from '@/components/messaging/types';
import { RecordHistory } from '@/components/record-history';
import { ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import type { ActionSheetItem } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { IconButton } from '@/components/ui/icon-button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { OverdueFlag, StatusBadge } from '@/components/ui/status-badge';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
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
    refund as refundRoute,
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
    refund?: boolean;
};

/**
 * Estimate or invoice (UX-REDESIGN §10.2, §10.4): where it stands in large type on top, one main action for its
 * state at the bottom (Take payment; Send / Mark approved / Create invoice by the estimate's stage), "Send ▾" with
 * Email, SMS and Copy link, PDF in the header, and the rare and dangerous actions (Refund, Void, Edit a paid
 * invoice, Delete) under ⋯ with a confirmation.
 */
export default function BillingShow({
    document: doc,
    can,
    paymentMethods = [],
    today,
    online = null,
    delivery,
    sms = null,
    reviewPrompt = null,
    signature = null,
    diagnosisUrl = null,
}: {
    document: BillingDocument;
    can: Can;
    paymentMethods?: Option[];
    today: string;
    online?: OnlinePayment;
    delivery?: Delivery;
    sms?: DocumentSms;
    reviewPrompt?: ReviewPrompt;
    signature?: {
        job_id: number;
        customer_name: string;
        data: SignatureData;
        can_sign: boolean;
    } | null;
    /** "Invoice diagnosis only" on a declined estimate, when a diagnostic fee is set. */
    diagnosisUrl?: string | null;
}) {
    const canViewHistory = Boolean(usePage().props.auth.can?.viewChangeLog);
    const t = useTrans();
    const phoneText = usePhone();
    const money = useMoney(doc.currency);
    const time = useCompanyTime();
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [takeOpen, setTakeOpen] = useState(false);
    const [voidOpen, setVoidOpen] = useState(false);
    const [refundOpen, setRefundOpen] = useState(false);
    const [confirm, setConfirm] = useState<
        null | 'delete' | 'revise' | 'edit-paid'
    >(null);
    const [error, setError] = useState<string | undefined>();
    const isInvoice = doc.kind === 'invoice';
    const group = isInvoice ? 'invoices' : 'estimates';
    const balance = doc.balance ?? 0;
    const kindLabel = t(isInvoice ? 'documents.invoice' : 'documents.estimate');
    const send = useDocumentDelivery({
        delivery: delivery ?? {
            pdf_url: '',
            send_url: '',
            public_url: null,
            link_url: '',
            receipt_url: null,
            can_send: false,
            sent_at: null,
            sent_to: null,
            viewed_at: null,
            email: '',
            message: '',
        },
        kindLabel,
        number: doc.number,
        sms,
        reviewPrompt,
    });
    // An estimate saved but not sent is a draft; once it went out it waits for the customer ("Sent").
    const stage =
        !isInvoice && doc.status === 'draft' && delivery?.sent_at
            ? 'sent'
            : doc.status;
    const editUrl = isInvoice ? editInvoice(doc.id) : editEstimate(doc.id);
    const paidSomething = isInvoice && (doc.amount_paid ?? 0) > 0;

    const options = {
        preserveScroll: true,
        onSuccess: () => setError(undefined),
        onError: (errors: Record<string, string>) =>
            setError(Object.values(errors)[0]),
    };

    const setDecision = (approved: boolean) =>
        router.put(decide(doc.id).url, { approved }, options);

    const toInvoice = () => router.post(convert(doc.id).url, {}, options);

    const editDocument = () =>
        paidSomething ? setConfirm('edit-paid') : router.visit(editUrl);

    const takePayment = () =>
        online ? setTakeOpen(true) : setPaymentOpen(true);

    const removePayment = (payment: PaymentData) => {
        if (
            !window.confirm(
                t('payments.confirm_void', { amount: money(payment.amount) }),
            )
        ) {
            return;
        }

        const reason = window.prompt(t('payments.void_reason_prompt')) ?? '';
        router.post(voidPayment(payment.id).url, { reason }, options);
    };

    // What the big line on top says.
    const headline = isInvoice
        ? doc.status === 'void'
            ? { text: doc.status_label, tone: 'text-muted-foreground' }
            : doc.status === 'refunded' || doc.status === 'partially_refunded'
              ? {
                    text: `${doc.status_label} · ${money(doc.amount_paid ?? 0)}`,
                    tone: '',
                }
              : balance <= 0
                ? {
                      text: t('billing.headline.paid', {
                          amount: money(doc.total),
                      }),
                      tone: 'text-success',
                  }
                : (doc.due_in_days ?? 0) < 0
                  ? {
                        text: t('billing.headline.overdue', {
                            count: -(doc.due_in_days ?? 0),
                            amount: money(balance),
                        }),
                        tone: 'text-danger',
                    }
                  : doc.due_on
                    ? {
                          text: t('billing.headline.due', {
                              date: time.dateOnly(doc.due_on),
                              amount: money(balance),
                          }),
                          tone: '',
                      }
                    : {
                          text: t('billing.headline.balance', {
                              amount: money(balance),
                          }),
                          tone: '',
                      }
        : { text: money(doc.total), tone: '' };

    const moreItems: (ActionSheetItem | false | undefined)[] = isInvoice
        ? [
              can.update && {
                  key: 'edit',
                  label: t('invoices.edit'),
                  icon: <Pencil />,
                  onSelect: editDocument,
              },
              can.refund && {
                  key: 'refund',
                  label: t('payments.refunds.action'),
                  icon: <Undo2 />,
                  onSelect: () => setRefundOpen(true),
              },
              can.void && ('separator' as const),
              can.void && {
                  key: 'void',
                  label: t('invoices.void'),
                  icon: <Ban />,
                  destructive: true,
                  onSelect: () => setVoidOpen(true),
              },
          ]
        : [
              stage === 'sent' &&
                  send.canSend && {
                      key: 'resend',
                      label: t('documents.resend'),
                      icon: <Receipt />,
                      onSelect: send.openEmail,
                  },
              stage !== 'draft' &&
                  can.update && {
                      key: 'edit',
                      label: t('estimates.edit'),
                      icon: <Pencil />,
                      onSelect: () => router.visit(editUrl),
                  },
              can.update &&
                  stage === 'declined' && {
                      key: 'approve',
                      label: t('estimates.mark_approved'),
                      icon: <CheckCircle2 />,
                      onSelect: () => setDecision(true),
                  },
              can.update &&
                  stage === 'approved' &&
                  !doc.online_approval && {
                      key: 'decline',
                      label: t('estimates.mark_declined'),
                      icon: <XCircle />,
                      onSelect: () => setDecision(false),
                  },
              can.revise && {
                  key: 'revise',
                  label: t('estimates.revise'),
                  icon: <FilePen />,
                  onSelect: () => setConfirm('revise'),
              },
              can.delete && ('separator' as const),
              can.delete && {
                  key: 'delete',
                  label: t('common.delete'),
                  icon: <Trash2 />,
                  destructive: true,
                  onSelect: () => setConfirm('delete'),
              },
          ];

    // The bottom bar: one main action for the state, at most one more.
    const bar = (() => {
        if (isInvoice) {
            if (doc.status === 'void') {
                return null;
            }

            if (balance > 0 && can.recordPayment) {
                return (
                    <>
                        <SendMenu actions={send} />
                        <Button
                            className="h-12 text-base md:h-11"
                            onClick={takePayment}
                            data-test="take-payment"
                        >
                            <CreditCard /> {t('payments.take')} ·{' '}
                            {money(balance)}
                        </Button>
                    </>
                );
            }

            return (
                <>
                    <SendMenu actions={send} />
                    {send.openReceipt && (
                        <Button
                            variant="outline"
                            className="h-12 md:h-11"
                            onClick={send.openReceipt}
                            data-test="send-receipt"
                        >
                            <Receipt /> {t('documents.receipt.send')}
                        </Button>
                    )}
                </>
            );
        }

        switch (stage) {
            case 'draft':
                return (
                    <>
                        {can.update && (
                            <Button
                                variant="outline"
                                className="h-12 md:h-11"
                                asChild
                            >
                                <Link href={editUrl}>
                                    <Pencil /> {t('common.edit')}
                                </Link>
                            </Button>
                        )}
                        <SendMenu actions={send} primary />
                    </>
                );
            case 'sent':
                return can.update ? (
                    <>
                        <Button
                            variant="outline"
                            className="h-12 md:h-11"
                            onClick={() => setDecision(false)}
                        >
                            <XCircle /> {t('estimates.mark_declined')}
                        </Button>
                        <Button
                            className="h-12 text-base md:h-11"
                            onClick={() => setDecision(true)}
                        >
                            <CheckCircle2 /> {t('estimates.mark_approved')}
                        </Button>
                    </>
                ) : null;
            case 'approved':
                return can.convert ? (
                    <Button
                        className="h-12 text-base md:h-11"
                        onClick={toInvoice}
                        data-test="create-invoice"
                    >
                        <Receipt /> {t('estimates.convert')}
                    </Button>
                ) : null;
            case 'declined':
                return diagnosisUrl ? (
                    <Button variant="outline" className="h-12 md:h-11" asChild>
                        <Link href={diagnosisUrl}>
                            <Stethoscope /> {t('estimates.invoice_diagnosis')}
                        </Link>
                    </Button>
                ) : null;
            default:
                return null;
        }
    })();

    return (
        <>
            <Head title={t(`${group}.number`, { number: doc.number })} />

            <ScreenHeader
                title={t(`${group}.number`, { number: doc.number })}
                subtitle={[doc.customer.display_name, doc.brand]
                    .filter(Boolean)
                    .join(' · ')}
                back={showJob(doc.job.id)}
                actions={
                    <>
                        {delivery && (
                            <IconButton
                                label={t('documents.download_pdf')}
                                variant="header"
                                asChild
                            >
                                <a
                                    href={delivery.pdf_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    data-test="document-pdf"
                                >
                                    <Download />
                                </a>
                            </IconButton>
                        )}
                        <ActionSheet
                            title={t(`${group}.number`, { number: doc.number })}
                            items={moreItems}
                        />
                    </>
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 sm:p-6">
                {/* Where it stands, in large type. */}
                <section
                    className="da-card space-y-2 p-4"
                    data-test="document-status"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        {isInvoice ? (
                            <DocumentStatusBadge
                                status={doc.status}
                                label={doc.status_label}
                            />
                        ) : (
                            <StatusBadge
                                status={stage}
                                label={
                                    stage === 'sent'
                                        ? t('estimates.statuses.sent')
                                        : doc.status_label
                                }
                            />
                        )}
                        {doc.overdue && <OverdueFlag />}
                        {/* Saved but not sent yet: the customer has not seen it and it can still be edited. */}
                        {isInvoice &&
                            delivery &&
                            !delivery.sent_at &&
                            doc.status === 'unpaid' && (
                                <span className="da-badge rounded-full px-2.5 text-xs leading-6 font-semibold">
                                    {t('billing.not_sent')}
                                </span>
                            )}
                    </div>
                    <p
                        className={`text-2xl font-bold tabular-nums ${headline.tone}`}
                        data-test="document-headline"
                    >
                        {headline.text}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {[
                            t('billing.issued', {
                                date: time.dateOnly(doc.issued_on),
                            }),
                            doc.valid_until
                                ? doc.expired
                                    ? t('estimates.expired_on', {
                                          date: time.dateOnly(doc.valid_until),
                                      })
                                    : t('estimates.valid_until_date', {
                                          date: time.dateOnly(doc.valid_until),
                                      })
                                : null,
                            doc.approved_at
                                ? t('estimates.approved_at', {
                                      date: time.date(doc.approved_at),
                                  })
                                : null,
                            doc.declined_at
                                ? t('estimates.declined_at', {
                                      date: time.date(doc.declined_at),
                                  })
                                : null,
                            doc.created_by
                                ? t('billing.created_by', {
                                      name: doc.created_by,
                                  })
                                : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {(doc.invoice || doc.estimate) && (
                        <p className="text-sm">
                            {doc.invoice && (
                                <Link
                                    href={showInvoice(doc.invoice.id)}
                                    className="font-medium underline"
                                >
                                    {t('estimates.invoiced_as', {
                                        number: doc.invoice.number,
                                    })}
                                </Link>
                            )}
                            {doc.estimate && (
                                <Link
                                    href={showEstimate(doc.estimate.id)}
                                    className="font-medium underline"
                                >
                                    {t('invoices.from_estimate', {
                                        number: doc.estimate.number,
                                    })}
                                </Link>
                            )}
                        </p>
                    )}
                    {delivery && <DeliveryStatus delivery={delivery} />}
                    {doc.voided_at && (
                        <p className="text-sm text-muted-foreground">
                            {t('invoices.voided_by', {
                                date: time.date(doc.voided_at),
                                name: doc.voided_by ?? '—',
                            })}
                            {doc.void_reason && ` — ${doc.void_reason}`}
                        </p>
                    )}
                </section>

                <InputError message={error} />

                {/* Customer and job */}
                <section className="da-card grid gap-1 p-4 text-sm">
                    <Link
                        href={showCustomer(doc.customer.id)}
                        className="font-semibold hover:underline"
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
                    <ul className="da-card divide-y overflow-hidden">
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
                                        {(!item.taxable ||
                                            item.tax_rate_ids?.length === 0) &&
                                            ` · ${t('billing.not_taxable')}`}
                                        {item.taxable &&
                                            item.tax_names.length > 0 &&
                                            ` · ${item.tax_names.join(', ')}`}
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
                                        {item.private_difference !== null &&
                                            item.private_difference !==
                                                undefined &&
                                            ` · ${t('billing.line.difference', { amount: money(item.private_difference) })}`}
                                        {item.total_cost !== null &&
                                            item.total_cost > 0 &&
                                            ` · ${t('billing.line.total_cost')} ${money(item.total_cost)}${item.supplier ? ` (${item.supplier})` : ''}`}
                                    </p>
                                </div>
                                {item.optional && !item.selected ? (
                                    <span className="text-right text-sm text-muted-foreground tabular-nums">
                                        {t('billing.line.optional_extra', {
                                            amount: money(item.total),
                                        })}
                                    </span>
                                ) : (
                                    <span className="font-medium tabular-nums">
                                        {money(item.total)}
                                    </span>
                                )}
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
                                onClick={() => setConfirm('revise')}
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
                        <ul className="da-card divide-y overflow-hidden text-sm">
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

                {/* Customer signature: taken when the work is done and invoiced */}
                {signature && (signature.data || signature.can_sign) && (
                    <SignatureSection
                        jobId={signature.job_id}
                        signature={signature.data}
                        customerName={signature.customer_name}
                        canSign={signature.can_sign}
                        reloadProps={['signature']}
                    />
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
                            <ul className="da-card divide-y overflow-hidden">
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
                                            {p.refund_reason && (
                                                <p className="text-xs">
                                                    {p.refund_reason}
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
                    </section>
                )}

                {canViewHistory && (
                    <RecordHistory type={doc.kind} id={doc.id} />
                )}
            </div>

            {bar && <StickyActionBar>{bar}</StickyActionBar>}

            {send.dialogs}

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
            {isInvoice && online && balance > 0 && (
                <Sheet open={takeOpen} onOpenChange={setTakeOpen}>
                    <SheetContent
                        side="bottom"
                        className="mx-auto max-h-[92dvh] max-w-3xl gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                        data-test="take-payment-sheet"
                    >
                        <SheetHeader className="p-0 pr-8">
                            <SheetTitle>
                                {t('payments.take')} · {money(balance)}
                            </SheetTitle>
                            <SheetDescription>
                                {t('payments.take_hint')}
                            </SheetDescription>
                        </SheetHeader>
                        <OnlinePaymentSection
                            invoiceId={doc.id}
                            online={online}
                            balance={balance}
                            currency={doc.currency}
                        />
                        <Button
                            variant="outline"
                            className="h-12 w-full text-base"
                            onClick={() => {
                                setTakeOpen(false);
                                setPaymentOpen(true);
                            }}
                        >
                            <CreditCard /> {t('payments.record')}
                        </Button>
                    </SheetContent>
                </Sheet>
            )}
            {can.refund && (
                <RefundDialog
                    open={refundOpen}
                    onOpenChange={setRefundOpen}
                    invoiceId={doc.id}
                    paid={doc.amount_paid ?? 0}
                    currency={doc.currency}
                />
            )}
            {can.void && (
                <VoidInvoiceDialog
                    open={voidOpen}
                    onOpenChange={setVoidOpen}
                    invoiceId={doc.id}
                />
            )}
            <ConfirmDialog
                open={confirm === 'delete'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('estimates.confirm_delete', { number: doc.number })}
                confirmLabel={t('common.delete')}
                destructive
                onConfirm={() => {
                    setConfirm(null);
                    router.delete(destroyEstimate(doc.id).url);
                }}
            />
            <ConfirmDialog
                open={confirm === 'revise'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('estimates.revise')}
                description={t('estimates.confirm_revise')}
                confirmLabel={t('estimates.revise')}
                onConfirm={() => {
                    setConfirm(null);
                    router.post(reviseRoute(doc.id).url, {}, options);
                }}
            />
            <ConfirmDialog
                open={confirm === 'edit-paid'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={t('invoices.edit')}
                description={t('invoices.edit_paid_warning')}
                confirmLabel={t('invoices.edit')}
                onConfirm={() => {
                    setConfirm(null);
                    router.visit(editUrl);
                }}
            />
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
                        variant="destructiveSolid"
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

function RefundDialog({
    open,
    onOpenChange,
    invoiceId,
    paid,
    currency,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceId: number;
    paid: number;
    currency: string;
}) {
    const t = useTrans();
    const money = useMoney(currency);
    const form = useForm({ amount: fromMinor(paid, currency), reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(refundRoute(invoiceId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('payments.refunds.title')}</DialogTitle>
                    <DialogDescription>
                        {t('payments.refunds.hint')}
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <FormField
                        id="refund-amount"
                        label={`${t('payments.refunds.amount')} (≤ ${money(paid)})`}
                        error={form.errors.amount}
                    >
                        <Input
                            id="refund-amount"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="refund-reason"
                        label={t('payments.refunds.reason')}
                        error={form.errors.reason}
                    >
                        <Textarea
                            id="refund-reason"
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
                        className="w-full"
                        disabled={
                            form.processing || form.data.reason.trim() === ''
                        }
                    >
                        {t('payments.refunds.action')}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
