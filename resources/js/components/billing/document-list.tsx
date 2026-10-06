import { Link } from '@inertiajs/react';
import { FileText, Receipt } from 'lucide-react';
import { DocumentStatusBadge } from '@/components/billing/document-status-badge';
import { useMoney } from '@/components/billing/money';
import type { DocumentRow } from '@/components/billing/types';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show as showEstimate } from '@/routes/estimates';
import { show as showInvoice } from '@/routes/invoices';

/**
 * Estimates and invoices as tappable rows (job page, customer card).
 */
export function DocumentList({
    documents,
    showCustomer = false,
}: {
    documents: DocumentRow[];
    showCustomer?: boolean;
}) {
    const t = useTrans();
    const money = useMoney();
    const time = useCompanyTime();

    return (
        <ul className="divide-y rounded-lg border">
            {documents.map((d) => {
                const Icon = d.kind === 'invoice' ? Receipt : FileText;
                const href =
                    d.kind === 'invoice'
                        ? showInvoice(d.id)
                        : showEstimate(d.id);

                return (
                    <li key={`${d.kind}-${d.id}`}>
                        <Link
                            href={href}
                            className="flex min-h-14 items-center gap-3 p-3 hover:bg-muted/50"
                        >
                            <Icon className="size-5 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {t(
                                            d.kind === 'invoice'
                                                ? 'invoices.number'
                                                : 'estimates.number',
                                            { number: d.number },
                                        )}
                                    </span>
                                    <DocumentStatusBadge
                                        status={d.status}
                                        label={d.status_label}
                                        overdue={d.overdue}
                                    />
                                </div>
                                <div className="truncate text-xs text-muted-foreground">
                                    {[
                                        showCustomer ? d.customer : null,
                                        time.dateOnly(d.issued_on),
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            </div>
                            <div className="text-right">
                                <div className="font-medium tabular-nums">
                                    {money(d.total, d.currency)}
                                </div>
                                {d.balance !== null &&
                                    d.balance > 0 &&
                                    d.status !== 'void' && (
                                        <div className="text-xs text-amber-700 tabular-nums dark:text-amber-300">
                                            {t('invoices.balance')}:{' '}
                                            {money(d.balance, d.currency)}
                                        </div>
                                    )}
                            </div>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
