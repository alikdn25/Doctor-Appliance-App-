import { Head, Link, router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { trash as deletedJobs } from '@/routes/jobs';
import { index, restore } from '@/routes/trash';

type DeletedItem = {
    key: string;
    type: string;
    id: number;
    label: string;
    detail: string | null;
    deleted_at: string | null;
    restorable_until: string | null;
};

/**
 * Deleted items: everything but jobs deleted in the last 30 days, with Restore (jobs have their own list).
 */
export default function Trash({
    items,
    days,
}: {
    items: DeletedItem[];
    days: number;
}) {
    const t = useTrans();
    const time = useCompanyTime();

    return (
        <>
            <Head title={t('trash.title')} />

            <div className="max-w-3xl space-y-4 p-4">
                <PageHeader
                    title={t('trash.title')}
                    description={t('trash.hint', { days })}
                    actions={
                        <Button asChild variant="outline" className="h-11">
                            <Link href={deletedJobs()}>
                                {t('trash.deleted_jobs')}
                            </Link>
                        </Button>
                    }
                />

                {items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('trash.empty', { days })}
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border bg-card shadow-sm">
                        {items.map((item) => (
                            <li
                                key={item.key}
                                className="flex items-center gap-3 p-3 text-sm"
                                data-test="deleted-item"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        <span className="text-muted-foreground">
                                            {t(`trash.types.${item.type}`)}
                                        </span>{' '}
                                        · {item.label}
                                    </p>
                                    {item.detail && (
                                        <p className="truncate text-muted-foreground">
                                            {item.detail}
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            item.deleted_at
                                                ? t('trash.deleted_on', {
                                                      date: time.dateTime(
                                                          item.deleted_at,
                                                      ),
                                                  })
                                                : null,
                                            item.restorable_until
                                                ? t('trash.until', {
                                                      date: time.date(
                                                          item.restorable_until,
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
                                    className="h-11 shrink-0"
                                    onClick={() =>
                                        router.post(
                                            restore({
                                                type: item.type,
                                                id: item.id,
                                            }).url,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <RotateCcw /> {t('trash.restore')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Trash.layout = {
    breadcrumbs: [{ title: 'trash.title', href: index() }],
};
