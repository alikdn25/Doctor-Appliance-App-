import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    CalendarDays,
    CalendarOff,
    MapPinOff,
    MessageSquare,
    Package,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { index as invoicesIndex } from '@/routes/invoices';
import { show as showJob } from '@/routes/jobs';
import { today as todayRoute } from '@/routes';

type JobLine = {
    id: number;
    number: number;
    customer: string | null;
    status_label: string;
    part: string | null;
    part_number: string | null;
};

/** A job without a time: "No time" (a day) or "No date" (none yet). */
type UntimedJob = {
    id: number;
    number: number;
    customer: string | null;
    address: string | null;
    status_label: string;
    assignees: string[];
    problem: string | null;
};

type Summary = {
    date: string;
    no_date?: UntimedJob[];
    no_time?: UntimedJob[];
    jobs: {
        id: number;
        number: number;
        customer: string | null;
        address: string | null;
        start: string;
        end: string;
        technicians: string[];
        status_label: string;
    }[];
    no_address: JobLine[];
    texts: {
        phone: string;
        name: string;
        preview: string;
        since: string | null;
        urgent: boolean;
        url: string;
    }[];
    unpaid: { count: number; totals: string[] } | null;
    parts: JobLine[];
};

function Block({
    icon,
    title,
    count,
    children,
}: {
    icon: ReactNode;
    title: string;
    count?: number;
    children: ReactNode;
}) {
    return (
        <section className="da-card overflow-hidden">
            <h2 className="flex items-center gap-2 border-b px-4 py-3 font-semibold [&_svg]:size-5 [&_svg]:text-muted-foreground">
                {icon}
                <span className="flex-1">{title}</span>
                {count !== undefined && (
                    <span className="da-badge rounded-full px-2.5 text-sm leading-6">
                        {count}
                    </span>
                )}
            </h2>
            {children}
        </section>
    );
}

/**
 * "Today" (owner's task of November 18): the morning summary as a screen — the day's jobs in order, jobs without an
 * address, unanswered texts (from the night too), unpaid invoices and parts to receive. Built by code, no AI.
 */
export default function Today({ summary }: { summary: Summary }) {
    const t = useTrans();
    const time = useCompanyTime();
    const empty = (
        <p className="px-4 py-3 text-sm text-muted-foreground">
            {t('automation.today.nothing')}
        </p>
    );
    const jobRow = (job: JobLine, extra?: string | null) => (
        <li key={job.id}>
            <Link
                href={showJob(job.id)}
                className="flex min-h-12 items-center gap-2 px-4 py-2 text-sm hover:bg-muted/40"
            >
                <span className="min-w-0 flex-1 truncate">
                    <span className="font-semibold">{job.customer}</span>{' '}
                    <span className="text-muted-foreground">#{job.number}</span>
                    {extra && (
                        <span className="block truncate text-xs text-muted-foreground">
                            {extra}
                        </span>
                    )}
                </span>
                <span className="text-xs text-muted-foreground">
                    {job.status_label}
                </span>
            </Link>
        </li>
    );

    const noDate = summary.no_date ?? [];
    const noTime = summary.no_time ?? [];
    const untimedRow = (job: UntimedJob, label: string) => (
        <li key={`untimed-${job.id}`}>
            <Link
                href={showJob(job.id)}
                className="flex min-h-14 items-start gap-3 px-4 py-2 hover:bg-muted/40"
                data-test="today-untimed-job"
            >
                <span className="mt-0.5 shrink-0 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-bold text-amber-900 dark:bg-amber-800 dark:text-amber-50">
                    {label}
                </span>
                <span className="min-w-0 flex-1 text-sm">
                    <span className="block font-semibold">
                        {job.customer}{' '}
                        <span className="font-normal text-muted-foreground">
                            #{job.number}
                        </span>
                    </span>
                    <span className="block truncate text-muted-foreground">
                        {job.address ?? '—'}
                    </span>
                    {job.assignees.length > 0 && (
                        <span className="block text-xs text-muted-foreground">
                            {job.assignees.join(', ')}
                        </span>
                    )}
                </span>
                <span className="text-xs text-muted-foreground">
                    {job.status_label}
                </span>
            </Link>
        </li>
    );

    return (
        <>
            <Head title={t('automation.today.title')} />
            <ScreenHeader
                title={t('automation.today.title')}
                subtitle={time.dateOnly(summary.date)}
            />
            <div className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-28 sm:p-6">
                {noDate.length > 0 && (
                    <Block
                        icon={<CalendarOff />}
                        title={t('automation.today.no_date')}
                        count={noDate.length}
                    >
                        <p className="px-4 pt-3 text-xs text-muted-foreground">
                            {t('automation.today.no_date_hint')}
                        </p>
                        <ul className="divide-y" data-test="today-no-date">
                            {noDate.map((job) =>
                                untimedRow(job, t('automation.today.no_date')),
                            )}
                        </ul>
                    </Block>
                )}

                <Block
                    icon={<CalendarDays />}
                    title={t('automation.today.jobs')}
                    count={summary.jobs.length + noTime.length}
                >
                    {noTime.length > 0 && (
                        <ul
                            className="divide-y border-b"
                            data-test="today-no-time"
                        >
                            {noTime.map((job) =>
                                untimedRow(job, t('automation.today.no_time')),
                            )}
                        </ul>
                    )}
                    {summary.jobs.length === 0 && noTime.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            {t('automation.today.no_jobs')}
                        </p>
                    ) : summary.jobs.length === 0 ? null : (
                        <ol className="divide-y" data-test="today-jobs">
                            {summary.jobs.map((job, i) => (
                                <li key={`${job.id}-${i}`}>
                                    <Link
                                        href={showJob(job.id)}
                                        className="flex min-h-14 items-start gap-3 px-4 py-2 hover:bg-muted/40"
                                    >
                                        <span className="da-soft mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold">
                                            {i + 1}
                                        </span>
                                        <span className="min-w-0 flex-1 text-sm">
                                            <span className="block font-bold tabular-nums">
                                                {time.shortRange(
                                                    job.start,
                                                    job.end,
                                                )}{' '}
                                                <span className="font-semibold">
                                                    {job.customer}
                                                </span>
                                            </span>
                                            <span className="block truncate text-muted-foreground">
                                                {job.address ?? '—'}
                                            </span>
                                            {job.technicians.length > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {job.technicians.join(', ')}
                                                </span>
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    )}
                </Block>

                <Block
                    icon={<MapPinOff />}
                    title={t('automation.today.no_address')}
                    count={summary.no_address.length}
                >
                    {summary.no_address.length === 0 ? (
                        empty
                    ) : (
                        <ul className="divide-y">
                            {summary.no_address.map((job) => jobRow(job))}
                        </ul>
                    )}
                </Block>

                {summary.unpaid !== null && (
                    <Block
                        icon={<MessageSquare />}
                        title={t('automation.today.texts')}
                        count={summary.texts.length}
                    >
                        {summary.texts.length === 0 ? (
                            empty
                        ) : (
                            <ul className="divide-y" data-test="today-texts">
                                {summary.texts.map((text) => (
                                    <li key={text.phone}>
                                        <a
                                            href={text.url}
                                            className="flex min-h-12 items-start gap-2 px-4 py-2 text-sm hover:bg-muted/40"
                                        >
                                            <span className="min-w-0 flex-1">
                                                <span className="font-semibold">
                                                    {text.name}
                                                </span>
                                                {text.urgent && (
                                                    <span className="ml-2 rounded-full bg-red-600 px-2 text-xs font-bold text-white">
                                                        {t(
                                                            'automation.inbox.urgent',
                                                        )}
                                                    </span>
                                                )}
                                                <span className="block truncate text-muted-foreground">
                                                    {text.preview}
                                                </span>
                                            </span>
                                            {text.since && (
                                                <span className="text-xs text-muted-foreground">
                                                    {time.dateTime(text.since)}
                                                </span>
                                            )}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Block>
                )}

                {summary.unpaid !== null && (
                    <Block
                        icon={<Banknote />}
                        title={t('automation.today.unpaid')}
                        count={summary.unpaid.count}
                    >
                        <Link
                            href={invoicesIndex()}
                            className="block px-4 py-3 text-sm hover:bg-muted/40"
                            data-test="today-unpaid"
                        >
                            {summary.unpaid.count === 0
                                ? t('automation.today.nothing')
                                : t('automation.today.unpaid_line', {
                                      count: summary.unpaid.count,
                                      total: summary.unpaid.totals.join(' + '),
                                  })}
                        </Link>
                    </Block>
                )}

                <Block
                    icon={<Package />}
                    title={t('automation.today.parts')}
                    count={summary.parts.length}
                >
                    {summary.parts.length === 0 ? (
                        empty
                    ) : (
                        <ul className="divide-y">
                            {summary.parts.map((job) =>
                                jobRow(
                                    job,
                                    [job.part, job.part_number]
                                        .filter(Boolean)
                                        .join(' · '),
                                ),
                            )}
                        </ul>
                    )}
                </Block>
            </div>
        </>
    );
}

Today.layout = {
    breadcrumbs: [{ title: 'automation.today.title', href: todayRoute() }],
};
