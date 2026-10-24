import { Link, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ChevronRight, Navigation, Phone } from 'lucide-react';
import type { ReactNode } from 'react';
import { applianceImageUrl } from '@/components/appliance-image';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { mapsUrl, telUrl } from '@/components/customers/types';
import { StatusBadge } from '@/components/jobs/status-badge';
import { isFinishedJob } from '@/components/jobs/types';
import { Button } from '@/components/ui/button';
import { IconButton } from '@/components/ui/icon-button';
import { OverdueFlag } from '@/components/ui/status-badge';
import { useTrans } from '@/lib/i18n';
import { isDraftJob } from '@/lib/status';
import { cn } from '@/lib/utils';

/** The three card buttons share the row on a phone: compact text and icon. */
export const cardButton =
    'h-12 gap-1.5 px-1.5 text-sm has-[>svg]:px-1.5 md:text-sm [&_svg]:size-4!';

export type JobCardData = {
    number: number;
    status: string;
    status_label: string;
    customer: string | null;
    customer_icon: AvatarIcon;
    phone: string | null;
    address: string | null;
    job_type_label: string;
    appliance_types: string[];
    problem: string | null;
    picture: string | null;
};

/**
 * The compact job card (UX-REDESIGN §6.1, §24): the arrival window in bold with the status, the customer, the
 * address and "Washer · problem" on one line each, the appliance picture on the right, and three equal buttons
 * below: Navigate (light blue), Call (green) and the visit step, e.g. On my way (white). Tapping the card opens
 * the job.
 */
export function JobCard({
    job,
    href,
    time,
    extra,
    below,
    action,
    accent = false,
    overdue = false,
    office = false,
    assignees = [],
}: {
    job: JobCardData;
    href: NonNullable<InertiaLinkProps['href']>;
    time: string | null;
    extra?: ReactNode;
    /** A row under the card's link, e.g. "Not assigned" that opens the technician choice. */
    below?: ReactNode;
    /** The third button (On my way, Start job, Finish visit); without it Navigate and Call share the row. */
    action?: ReactNode;
    /** The next job by time: an accent border. */
    accent?: boolean;
    overdue?: boolean;
    /** The office list (§7.4): no Navigate, Call as an icon, the assignee's initials on the right. */
    office?: boolean;
    assignees?: string[];
}) {
    const t = useTrans();
    const worksAlone = usePage().props.auth.company?.works_alone ?? false;
    const types = job.appliance_types.join(', ');
    const several = job.appliance_types.length > 1;
    const installation = job.picture === 'installation';
    const line = [
        installation
            ? job.job_type_label
            : several
              ? t('jobs.mine_multiple')
              : types,
        installation || several ? types : job.problem,
    ]
        .filter(Boolean)
        .join(' · ');
    const buttons = office
        ? 0
        : [job.address, job.phone, action].filter(Boolean).length;
    const initials = (name: string) =>
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase())
            .join('');

    return (
        <li
            data-test="job-card"
            data-accent={accent || undefined}
            data-draft={isDraftJob(job.status) || undefined}
            className={cn(
                'da-card overflow-hidden',
                office && 'flex items-center pr-3',
                accent && 'ring-2 ring-link',
                isDraftJob(job.status) && 'da-draft',
                // Finished work is muted (grey) in every list.
                isFinishedJob(job.status) && 'opacity-60 grayscale',
            )}
        >
            <Link
                href={href}
                // Start loading the job on hover or press; a short cache keeps statuses fresh.
                prefetch
                cacheFor="5s"
                className={cn(
                    'flex min-w-0 flex-1 items-start gap-3 p-3 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
                    !office && 'pb-2',
                )}
            >
                <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-sm">
                    <div className="flex min-w-0 items-center gap-1.5">
                        {time && (
                            <span className="shrink-0 text-base font-bold whitespace-nowrap tabular-nums">
                                {time}
                            </span>
                        )}
                        <StatusBadge
                            status={job.status}
                            label={job.status_label}
                        />
                        {overdue && <OverdueFlag />}
                    </div>
                    <div className="truncate text-base font-semibold">
                        {job.customer}
                        <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                            #{job.number}
                        </span>
                    </div>
                    {job.address && (
                        <div className="truncate text-muted-foreground">
                            {job.address}
                        </div>
                    )}
                    {line !== '' && (
                        <div className="truncate text-muted-foreground">
                            {line}
                        </div>
                    )}
                    {extra && <div className="text-xs">{extra}</div>}
                </div>
                {office && (
                    <div className="flex shrink-0 flex-col items-end gap-2 self-center">
                        {assignees.length > 0 ? (
                            <span
                                className="flex -space-x-2"
                                title={assignees.join(', ')}
                            >
                                {assignees.slice(0, 2).map((name) => (
                                    <span
                                        key={name}
                                        className="da-soft inline-flex size-9 items-center justify-center rounded-full text-xs font-bold ring-2 ring-white"
                                        aria-label={name}
                                    >
                                        {initials(name)}
                                    </span>
                                ))}
                            </span>
                        ) : (
                            !worksAlone && (
                                <span className="text-xs font-medium text-amber-700">
                                    {t('jobs.unassigned')}
                                </span>
                            )
                        )}
                    </div>
                )}
                {!office && job.picture && (
                    <img
                        src={applianceImageUrl(job.picture)}
                        alt=""
                        loading="lazy"
                        className="size-12 shrink-0 self-center object-contain mix-blend-multiply"
                    />
                )}
            </Link>
            {below}
            {office && job.phone && (
                <IconButton label={t('jobs.call')} asChild>
                    <a href={telUrl(job.phone)}>
                        <Phone />
                    </a>
                </IconButton>
            )}
            {buttons > 0 && (
                <div
                    className={cn(
                        'grid gap-2 px-3 pb-3',
                        buttons === 3
                            ? 'grid-cols-3'
                            : buttons === 2
                              ? 'grid-cols-2'
                              : 'grid-cols-1',
                    )}
                >
                    {job.address && (
                        <Button asChild variant="sky" className={cardButton}>
                            <a
                                href={mapsUrl(job.address)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Navigation /> {t('jobs.navigate')}
                            </a>
                        </Button>
                    )}
                    {job.phone && (
                        <Button
                            asChild
                            variant="success"
                            className={cardButton}
                        >
                            <a href={telUrl(job.phone)}>
                                <Phone /> {t('jobs.call')}
                            </a>
                        </Button>
                    )}
                    {action}
                </div>
            )}
        </li>
    );
}

/** Saturated colours of the count circles above the list (one per status). */
export const STATUS_CIRCLES: Record<string, string> = {
    on_hold: 'linear-gradient(135deg,#FCA5A5,#DC2626 60%,#991B1B)',
    in_progress: 'linear-gradient(135deg,#FDBA74,#F97316 60%,#C2410C)',
    waiting_for_parts: 'linear-gradient(135deg,#D8B4FE,#9333EA 60%,#6B21A8)',
    on_the_way: 'linear-gradient(135deg,#FDE68A,#EAB308 60%,#A16207)',
    waiting_for_customer: 'linear-gradient(135deg,#D6B89C,#9C6B4E 60%,#6B4430)',
    cancelled: 'linear-gradient(135deg,#71717A,#27272A 60%,#09090B)',
    new: 'linear-gradient(135deg,#7DD3FC,#0EA5E9 60%,#0369A1)',
    scheduled: 'linear-gradient(135deg,#A5B4FC,#4F46E5 60%,#3730A3)',
    completed: 'linear-gradient(135deg,#86EFAC,#22C55E 60%,#15803D)',
    invoiced: 'linear-gradient(135deg,#5EEAD4,#14B8A6 60%,#0F766E)',
    paid: 'linear-gradient(135deg,#6EE7B7,#10B981 60%,#047857)',
};

/**
 * The row of coloured count circles above the list (mockup), with the arrow that opens their legend.
 * Tapping a circle shows only that status; tapping it again shows all.
 */
export function StatusCircles({
    counts,
    labels,
    selected,
    onSelect,
    open,
    onOpenChange,
}: {
    counts: Record<string, number>;
    labels: Record<string, string>;
    selected: string;
    onSelect: (status: string) => void;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTrans();
    const statuses = Object.keys(STATUS_CIRCLES).filter(
        (status) => (counts[status] ?? 0) > 0,
    );
    const pick = (status: string) =>
        onSelect(selected === status ? '' : status);

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <div className="-mx-1 flex min-w-0 flex-1 gap-2 overflow-x-auto px-1 py-1">
                    {statuses.map((status) => (
                        // A count circle with its status written next to it, so the colors need no legend.
                        <button
                            key={status}
                            type="button"
                            aria-pressed={selected === status}
                            aria-label={`${labels[status] ?? status}: ${counts[status]}`}
                            onClick={() => pick(status)}
                            className={cn(
                                'da-press flex min-h-12 shrink-0 items-center gap-2 rounded-full bg-white py-1 pr-3 pl-1 text-sm font-semibold text-[#0F1B2D] shadow-[0_4px_10px_-4px_rgba(0,36,73,.35)]',
                                selected === status &&
                                    'ring-3 ring-[#0A6CF5] ring-offset-2',
                            )}
                        >
                            <span
                                className="flex size-10 items-center justify-center rounded-full text-base font-bold text-white shadow-[inset_0_2px_2px_rgba(255,255,255,.45),inset_0_-4px_8px_rgba(0,0,0,.25)] [text-shadow:0_1px_2px_rgba(0,0,0,.35)]"
                                style={{ background: STATUS_CIRCLES[status] }}
                            >
                                {counts[status]}
                            </span>
                            <span className="whitespace-nowrap">
                                {labels[status] ?? status}
                            </span>
                        </button>
                    ))}
                </div>
                <button
                    type="button"
                    aria-label={t('jobs.status_legend')}
                    aria-expanded={open}
                    onClick={() => onOpenChange(!open)}
                    className="da-raised da-press flex size-12 shrink-0 items-center justify-center rounded-2xl"
                >
                    <ChevronRight
                        className={cn(
                            'size-6 rotate-90 transition-transform',
                            open && '-rotate-90',
                        )}
                        aria-hidden="true"
                    />
                </button>
            </div>
            {open && (
                <ul className="da-card grid grid-cols-2 gap-1 p-2">
                    {statuses.map((status) => (
                        <li key={status}>
                            <button
                                type="button"
                                aria-pressed={selected === status}
                                onClick={() => pick(status)}
                                className={cn(
                                    'flex min-h-11 w-full items-center gap-2 rounded-xl px-2 text-left text-sm font-medium',
                                    selected === status && 'bg-[#DCEAFE]',
                                )}
                            >
                                <span
                                    className="size-5 shrink-0 rounded-full"
                                    style={{
                                        background: STATUS_CIRCLES[status],
                                    }}
                                />
                                <span className="flex-1">{labels[status]}</span>
                                <span className="tabular-nums">
                                    {counts[status]}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
