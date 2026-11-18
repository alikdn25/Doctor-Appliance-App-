import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import * as debtors from '@/routes/invoices/debtors';

type Row = {
    line: number;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    date: string | null;
    work: string | null;
    amount: number | null;
    amount_text: string | null;
    note: string | null;
    customer: string | null;
    problems: string[];
};

/**
 * Import debtors (Owner; owner's task of November 19): a CSV → preview of every row (existing or new customer, what
 * is wrong) → Confirm creates the customers, Completed jobs and open invoices that are not sent.
 */
export default function ImportDebtors({ columns }: { columns: string[] }) {
    const t = useTrans();
    const [file, setFile] = useState<File | null>(null);
    const [rows, setRows] = useState<Row[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const read = async () => {
        if (!file) return;
        setBusy(true);
        setError(null);
        const body = new FormData();
        body.append('file', file);
        try {
            const response = await fetch(debtors.preview().url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body,
            });
            const data = (await response.json()) as {
                rows?: Row[];
                message?: string;
            };
            if (!response.ok) throw new Error(data.message ?? '');
            setRows(data.rows ?? []);
        } catch (e) {
            setError(e instanceof Error && e.message ? e.message : '…');
        } finally {
            setBusy(false);
        }
    };

    const good = (rows ?? []).filter((r) => r.problems.length === 0);
    const confirm = () =>
        router.post(debtors.store().url, {
            rows: good.map((r) => ({
                name: r.name,
                phone: r.phone ?? '',
                email: r.email ?? '',
                address: r.address ?? '',
                date: r.date ?? '',
                work: r.work ?? '',
                amount: r.amount_text ?? '',
                note: r.note ?? '',
            })),
        });

    return (
        <>
            <Head title={t('debtors.import.title')} />
            <ScreenHeader title={t('debtors.import.title')} />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 pb-28 sm:p-6">
                <p className="text-sm text-muted-foreground">
                    {t('debtors.import.hint', { columns: columns.join(', ') })}
                </p>
                <div className="da-card space-y-3 p-4">
                    <div className="space-y-1">
                        <Label htmlFor="debtors-file">
                            {t('debtors.import.file')}
                        </Label>
                        <Input
                            id="debtors-file"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) => {
                                setFile(e.target.files?.[0] ?? null);
                                setRows(null);
                            }}
                        />
                    </div>
                    <Button
                        size="lg"
                        className="w-full"
                        disabled={!file}
                        loading={busy}
                        onClick={() => void read()}
                        data-test="debtors-preview"
                    >
                        {t('debtors.import.read')}
                    </Button>
                    {error && (
                        <p className="text-sm text-red-700" role="alert">
                            {error}
                        </p>
                    )}
                </div>

                {rows !== null && (
                    <section className="space-y-3">
                        <p className="text-sm font-medium">
                            {t('debtors.import.rows', { count: rows.length })}
                            {rows.length !== good.length &&
                                ` · ${t('debtors.import.skipped')}`}
                        </p>
                        <ul className="da-card divide-y">
                            {rows.map((row) => (
                                <li
                                    key={row.line}
                                    className={cn(
                                        'space-y-0.5 p-3 text-sm',
                                        row.problems.length > 0 &&
                                            'bg-red-50 text-red-900',
                                    )}
                                >
                                    <p className="font-semibold">
                                        {row.name || '—'}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            {row.customer
                                                ? t('debtors.import.found', {
                                                      name: row.customer,
                                                  })
                                                : t(
                                                      'debtors.import.new_customer',
                                                  )}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        {[
                                            row.phone,
                                            row.email,
                                            row.address,
                                            row.date,
                                            row.work,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    <p className="font-medium">
                                        {row.amount_text ??
                                            t('debtors.needs_amount')}
                                    </p>
                                    {row.problems.length > 0 && (
                                        <p className="font-medium">
                                            {row.problems.join(', ')}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Button
                            size="lg"
                            className="w-full"
                            disabled={good.length === 0}
                            onClick={confirm}
                            data-test="debtors-confirm"
                        >
                            {t('debtors.import.confirm')} ({good.length})
                        </Button>
                    </section>
                )}
            </div>
        </>
    );
}
