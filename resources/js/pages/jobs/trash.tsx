import { Head, router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { index, restore, trash } from '@/routes/jobs';

type DeletedJob = {
    id: number;
    number: number;
    customer: string | null;
    address: string | null;
    brand: string | null;
    deleted_at: string | null;
    deleted_by: string | null;
    restorable_until: string | null;
};

/**
 * Jobs deleted in the last 30 days, with Restore.
 */
export default function JobsTrash({
    jobs,
    days,
}: {
    jobs: DeletedJob[];
    days: number;
}) {
    const t = useTrans();
    const time = useCompanyTime();

    return (
        <>
            <Head title={t('jobs.trash.title')} />

            <div className="max-w-3xl space-y-4 p-4">
                <PageHeader
                    title={t('jobs.trash.title')}
                    description={t('jobs.trash.hint', { days })}
                />

                {jobs.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('jobs.trash.empty', { days })}
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {jobs.map((job) => (
                            <li
                                key={job.id}
                                className="flex items-center gap-3 p-3 text-sm"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium">
                                        #{job.number} · {job.customer}
                                    </p>
                                    {job.address && (
                                        <p className="truncate text-muted-foreground">
                                            {job.address}
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            job.deleted_at
                                                ? t('jobs.trash.deleted_by', {
                                                      date: time.dateTime(
                                                          job.deleted_at,
                                                      ),
                                                      name:
                                                          job.deleted_by ?? '—',
                                                  })
                                                : null,
                                            job.restorable_until
                                                ? t('jobs.trash.until', {
                                                      date: time.date(
                                                          job.restorable_until,
                                                      ),
                                                  })
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    className="h-11"
                                    onClick={() =>
                                        router.post(restore(job.id).url)
                                    }
                                >
                                    <RotateCcw /> {t('jobs.restore')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

JobsTrash.layout = {
    breadcrumbs: [
        { title: 'jobs.title', href: index() },
        { title: 'jobs.trash.title', href: trash() },
    ],
};
