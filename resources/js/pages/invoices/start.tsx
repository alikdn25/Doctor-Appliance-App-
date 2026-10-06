import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { create as createJob } from '@/routes/jobs';
import { create, start } from '@/routes/invoices';

export default function StartInvoice({
    jobs,
    search: initialSearch,
}: {
    jobs: {
        id: number;
        number: number;
        customer: string;
        description: string | null;
        created_at: string | null;
        address: string | null;
        status_label: string;
        technicians: string[];
        invoices: string[];
    }[];
    search: string;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const [search, setSearch] = useState(initialSearch);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(
            start().url,
            { search },
            { preserveState: true, replace: true },
        );
    };
    return (
        <>
            <Head title={t('invoices.add')} />
            <div className="mx-auto w-full max-w-3xl p-4 sm:p-6">
                <PageHeader
                    title={t('invoices.add')}
                    description={t('invoices.choose_job')}
                    actions={
                        <Button asChild className="min-h-11">
                            <Link href={createJob({ query: { invoice: 1 } })}>
                                <Plus />
                                {t('invoices.new_customer_job')}
                            </Link>
                        </Button>
                    }
                />
                <form onSubmit={submit} className="mb-5 flex gap-2">
                    <Input
                        type="search"
                        value={search}
                        placeholder={t('invoices.search_jobs')}
                        aria-label={t('common.search')}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        {t('common.search')}
                    </Button>
                </form>
                <div className="space-y-3">
                    {jobs.map((job) => (
                        <Link
                            key={job.id}
                            href={create(job.id)}
                            className="block rounded-xl border bg-card p-4 transition-colors hover:bg-primary/5 focus-visible:outline-2 focus-visible:outline-ring"
                        >
                            <span className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-semibold">
                                    #{job.number} · {job.customer}
                                </span>
                                <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium">
                                    {job.status_label}
                                </span>
                            </span>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {[
                                    job.created_at
                                        ? time.date(job.created_at)
                                        : null,
                                    job.address,
                                    job.technicians.join(', ') || null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            {job.invoices.length > 0 && (
                                <p className="mt-1 text-sm font-medium text-amber-700 dark:text-amber-400">
                                    {t('invoices.job_invoices', {
                                        numbers: job.invoices.join(', '),
                                    })}
                                </p>
                            )}
                            {job.description && (
                                <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                    {job.description}
                                </p>
                            )}
                        </Link>
                    ))}
                </div>
                {jobs.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('invoices.no_jobs')}
                    </p>
                )}
            </div>
        </>
    );
}
