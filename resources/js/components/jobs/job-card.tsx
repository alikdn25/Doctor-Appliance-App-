import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import {
    ArrowRight,
    ChevronRight,
    Clock,
    MapPin,
    Navigation,
    Phone,
    WashingMachine,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { applianceImageUrl } from '@/components/appliance-image';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { mapsUrl, telUrl } from '@/components/customers/types';
import { StatusBadge } from '@/components/jobs/status-badge';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

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
 * The job card of the approved My Jobs mockup: face, number and status, time, name, address,
 * "Appliance • problem", the appliance picture, and Navigate · Call · View job.
 */
export function JobCard({
    job,
    href,
    time,
    extra,
    highlight = false,
}: {
    job: JobCardData;
    href: NonNullable<InertiaLinkProps['href']>;
    time: string | null;
    extra?: ReactNode;
    highlight?: boolean;
}) {
    const t = useTrans();
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
        .join(' • ');

    return (
        <li
            className={cn(
                'da-card overflow-hidden',
                highlight && 'ring-2 ring-[#FDBA74]',
            )}
        >
            <Link
                href={href}
                className="flex items-start gap-2.5 p-3 pb-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
            >
                <CustomerAvatar
                    icon={job.customer_icon}
                    name={job.customer ?? undefined}
                    size="lg"
                />
                <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-[13px]">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[17px] font-bold">
                            #{job.number}
                        </span>
                        <StatusBadge
                            status={job.status}
                            label={job.status_label}
                        />
                    </div>
                    {time && (
                        <div className="flex items-start gap-1.5 text-[15px] font-bold tabular-nums">
                            <Clock className="mt-0.5 size-4 shrink-0 text-[#0A6CF5]" />
                            {time}
                        </div>
                    )}
                    <div className="truncate text-base font-bold">
                        {job.customer}
                    </div>
                    {job.address && (
                        <div className="flex items-start gap-1.5 text-muted-foreground">
                            <MapPin className="mt-0.5 size-3.5 shrink-0" />
                            <span className="line-clamp-2">{job.address}</span>
                        </div>
                    )}
                    {line !== '' && (
                        <div className="flex items-start gap-1.5 text-muted-foreground">
                            <WashingMachine className="mt-0.5 size-3.5 shrink-0" />
                            <span className="line-clamp-2">{line}</span>
                        </div>
                    )}
                    {extra}
                </div>
                {job.picture && (
                    <img
                        src={applianceImageUrl(job.picture)}
                        alt=""
                        loading="lazy"
                        className="h-20 w-[76px] shrink-0 self-center object-contain mix-blend-multiply"
                    />
                )}
                <ChevronRight
                    aria-hidden="true"
                    className="size-4 shrink-0 self-center text-[#8A97A8]"
                />
            </Link>
            <div className="grid grid-cols-3 gap-2 px-3 pb-3">
                {job.address ? (
                    <Button asChild variant="outline" className="h-12">
                        <a
                            href={mapsUrl(job.address)}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Navigation /> {t('jobs.navigate')}
                        </a>
                    </Button>
                ) : (
                    <span />
                )}
                {job.phone ? (
                    <Button asChild variant="outline" className="h-12">
                        <a href={telUrl(job.phone)}>
                            <Phone /> {t('jobs.call')}
                        </a>
                    </Button>
                ) : (
                    <span />
                )}
                <Button asChild className="h-12">
                    <Link href={href}>
                        {t('jobs.view_job')} <ArrowRight />
                    </Link>
                </Button>
            </div>
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
