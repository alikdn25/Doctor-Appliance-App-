import { Head, router, usePage } from '@inertiajs/react';
import { CreditCard, Download } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { pay as payRoute, pdf as pdfRoute } from '@/routes/documents/public';

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
        description: string;
        quantity: string;
        unit_price: string;
        total: string;
        taxable: boolean;
    }[];
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
};

/**
 * The customer's online estimate / invoice (no login). Mobile first: the pay button is the main action.
 */
export default function PublicDocument({
    token,
    document: doc,
    canPay,
}: {
    token: string;
    document: PrintedDocument;
    canPay: boolean;
}) {
    const t = useTrans();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [paying, setPaying] = useState(false);
    const brandName = doc.brand.name ?? doc.brand.fallback_name;
    const due = (doc.balance_minor ?? 0) > 0;

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

                    <ul className="divide-y rounded-lg border text-sm">
                        {doc.items.map((item, i) => (
                            <li key={i} className="flex gap-3 p-3">
                                <div className="min-w-0 flex-1">
                                    <p className="whitespace-pre-line">
                                        {item.description}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('billing.qty_times_price', {
                                            quantity: item.quantity,
                                            price: item.unit_price,
                                        })}
                                    </p>
                                </div>
                                <span className="tabular-nums">
                                    {item.total}
                                </span>
                            </li>
                        ))}
                    </ul>

                    <dl className="ml-auto max-w-xs space-y-1 text-sm">
                        <Row
                            label={t('billing.subtotal')}
                            value={doc.subtotal}
                        />
                        {doc.discount && (
                            <Row
                                label={t('billing.discount')}
                                value={doc.discount}
                            />
                        )}
                        {doc.taxes.map((tax) => (
                            <Row
                                key={tax.label}
                                label={tax.label}
                                value={tax.amount}
                            />
                        ))}
                        <Row
                            bold
                            label={t('billing.total')}
                            value={doc.total}
                        />
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
                    </dl>

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
        </>
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
