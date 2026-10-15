import { Head, Link, router, usePage } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import {
    AlarmClock,
    Banknote,
    CalendarPlus,
    Car,
    Check,
    CheckCircle2,
    ChevronDown,
    FileText,
    Flag,
    History,
    KeyRound,
    ListChecks,
    MessageSquare,
    Navigation,
    Package,
    Pencil,
    Phone,
    Play,
    Plus,
    Receipt,
    Send,
    ShieldCheck,
    Trash2,
    User,
} from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ApplianceImage } from '@/components/appliance-image';
import { DocumentList } from '@/components/billing/document-list';
import { useMoney } from '@/components/billing/money';
import { PaymentDialog } from '@/components/billing/payment-dialog';
import type { DocumentRow } from '@/components/billing/types';
import {
    CustomerAvatar,
    CustomerAvatarPicker,
} from '@/components/customers/customer-avatar';
import type {
    AvatarIcon,
    AvatarStyle,
} from '@/components/customers/customer-avatar';
import { CustomerNotes } from '@/components/customers/customer-notes';
import { mapsUrl, telUrl } from '@/components/customers/types';
import type { PropertyData } from '@/components/customers/types';
import InputError from '@/components/input-error';
import { FinishDialog } from '@/components/jobs/finish-dialog';
import type {
    CallbackInfo,
    ClosureReasons,
} from '@/components/jobs/finish-dialog';
import { JobApplianceDialog } from '@/components/jobs/job-appliance-dialog';
import { JobCostsSection } from '@/components/jobs/job-costs';
import type { JobCosts } from '@/components/jobs/job-costs';
import {
    BringList,
    FollowUpLinks,
    OutcomeCard,
    StrictBadge,
} from '@/components/jobs/job-outcome';
import type { BringItemData } from '@/components/jobs/job-outcome';
import { PhotoSection } from '@/components/jobs/photo-section';
import type { JobPhotoData } from '@/components/jobs/photo-section';
import { RatingPlateButton } from '@/components/jobs/rating-plate-button';
import { SignatureSection } from '@/components/jobs/signature-section';
import type { SignatureData } from '@/components/jobs/signature-section';
import { StatusBadge } from '@/components/jobs/status-badge';
import { StatusDialog } from '@/components/jobs/status-dialog';
import type { ApplianceItem, Assignable, Visit } from '@/components/jobs/types';
import { applianceTitle } from '@/components/jobs/types';
import { VisitDialog } from '@/components/jobs/visit-dialog';
import { WarrantyDialog } from '@/components/jobs/warranty-dialog';
import type { WarrantyLine } from '@/components/jobs/warranty-dialog';
import {
    JobMessagingSection,
    openOnPhone,
    openSmsApp,
} from '@/components/messaging/job-messaging';
import type { JobMessaging } from '@/components/messaging/types';
import { RecordHistory } from '@/components/record-history';
import { ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { IconButton } from '@/components/ui/icon-button';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { Textarea } from '@/components/ui/textarea';
import { useAutosave } from '@/hooks/use-autosave';
import type { AutosaveState } from '@/hooks/use-autosave';
import { formatMinutes, useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';
import { cn } from '@/lib/utils';
import { show as showAppliance } from '@/routes/appliances';
import { show as showCustomer } from '@/routes/customers';
import { create as createEstimate } from '@/routes/estimates';
import {
    create as createInvoice,
    show as showInvoice,
} from '@/routes/invoices';
import { destroy, edit, index, techNotes } from '@/routes/jobs';
import {
    destroy as destroyVisit,
    finishScreen,
    onMyWay,
    start,
} from '@/routes/visits';
import type { Option } from '@/types';

type Job = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    job_type_label: string;
    visit_type: string;
    visit_type_label: string;
    previous_job: { id: number; number: number } | null;
    follow_ups: {
        id: number;
        number: number;
        visit_type_label: string;
        status_label: string;
    }[];
    outcome: string | null;
    outcome_label: string | null;
    outcome_reason: string | null;
    outcome_note: string | null;
    closed_at: string | null;
    closed_by: string | null;
    bring_items: BringItemData[];
    lead_source_label: string | null;
    brand: string | null;
    description: string | null;
    notes: string | null;
    tech_notes: string | null;
    created_at: string | null;
    completed_at: string | null;
    allows_visit_work: boolean;
    customer: {
        id: number;
        display_name: string;
        avatar_icon: AvatarIcon;
        avatar_style: AvatarStyle;
        notes: string | null;
        phones: {
            id: number;
            number: string;
            label_text: string;
            contact_name: string | null;
        }[];
    };
    property: PropertyData & { full_address: string };
    appliances: (ApplianceItem & { rating_plate_url: string | null })[];
    visits: Visit[];
    photos: JobPhotoData[];
    signature: SignatureData;
    estimates: DocumentRow[];
    invoices: DocumentRow[];
    minutes_on_job: number;
    history: {
        id: number;
        from_label: string | null;
        to: string;
        to_label: string;
        user: string | null;
        note: string | null;
        created_at: string | null;
    }[];
};

type Props = {
    job: Job;
    myVisitId: number | null;
    can: {
        update: boolean;
        delete: boolean;
        work: boolean;
        close: boolean;
        viewCustomer: boolean;
        updateCustomer: boolean;
    };
    statusOptions: Option[];
    closureReasons: ClosureReasons;
    callback: CallbackInfo;
    warrantyLines: WarrantyLine[];
    warrantyUnits: Option[];
    openWarranty: boolean;
    costs: JobCosts | null;
    assignableUsers: Assignable[];
    otherAppliances: ApplianceItem[];
    applianceTypes: Option[];
    manufacturers: string[];
    today: string;
    photoKinds: Option[];
    messaging: JobMessaging;
    /** Invoices with money owed that this person may mark as paid. */
    payable: {
        id: number;
        number: string;
        balance: number;
        currency: string;
    }[];
    paymentMethods: Option[];
};

/** Minutes since an ISO time, refreshed every 30 seconds. */
function useElapsedMinutes(since: string | null): number | null {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!since) {
            return;
        }

        const timer = setInterval(() => setNow(Date.now()), 30_000);

        return () => clearInterval(timer);
    }, [since]);

    return since
        ? Math.max(0, Math.floor((now - new Date(since).getTime()) / 60_000))
        : null;
}
type BarAction = {
    key: string;
    label: string;
    icon: ReactNode;
    onClick?: () => void;
    href?: NonNullable<InertiaLinkProps['href']>;
};

/** "Saving… / Saved" next to an autosaved field. */
function SaveState({ state }: { state: AutosaveState }) {
    const t = useTrans();

    if (state === 'idle') {
        return null;
    }

    return (
        <span
            role="status"
            data-test="autosave-state"
            className={cn(
                'flex items-center gap-1 text-xs font-medium',
                state === 'saved' && 'text-success',
                state === 'saving' && 'text-muted-foreground',
                state === 'error' && 'text-danger',
            )}
        >
            {state === 'saved' && <Check className="size-3.5" />}
            {t(`ui.autosave.${state}`)}
        </span>
    );
}

/** Work done: saves by itself a second after typing stops; no Save button. */
function WorkDone({ jobId, notes }: { jobId: number; notes: string | null }) {
    const t = useTrans();
    const [value, setValue] = useState(notes ?? '');
    const state = useAutosave(value, techNotes(jobId).url, 'tech_notes');

    return (
        <Section
            title={t('jobs.sections.tech_notes')}
            action={<SaveState state={state} />}
        >
            <Textarea
                rows={4}
                aria-label={t('jobs.fields.tech_notes')}
                placeholder={t('jobs.fields.tech_notes_placeholder')}
                value={value}
                maxLength={5000}
                onChange={(e) => setValue(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">
                {t('jobs.fields.tech_notes_autosave_hint')}
            </p>
        </Section>
    );
}

/** A titled block of the job page. */
function Section({
    title,
    action,
    id,
    children,
}: {
    title: string;
    action?: ReactNode;
    id?: string;
    children: ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-24 space-y-2">
            <div className="flex min-h-9 flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-semibold">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

/**
 * The job page (UX-REDESIGN §6.2), in the order of the work: customer (gate code large), problem and notes,
 * appliances with Scan rating plate, photos, Work done (autosaved), estimates and invoices, the signature, and
 * Activity folded at the end. The next step for the job's status sits in a bar at the bottom; rare actions
 * (close, change status, warranty, edit, delete) are under "⋯".
 */
export default function JobShow({
    job,
    myVisitId,
    can,
    statusOptions,
    assignableUsers,
    otherAppliances,
    applianceTypes,
    manufacturers,
    today,
    photoKinds,
    messaging,
    payable,
    paymentMethods,
    closureReasons,
    callback,
    warrantyLines,
    warrantyUnits,
    openWarranty,
    costs,
}: Props) {
    const t = useTrans();
    const phoneText = usePhone();
    const { auth } = usePage().props;
    // Handyman companies do not record appliances; show the section only when the job has some.
    const showAppliances =
        (auth.company?.tracks_appliances ?? true) || job.appliances.length > 0;
    const time = useCompanyTime();
    const [statusOpen, setStatusOpen] = useState(false);
    const [closeOpen, setCloseOpen] = useState(false);
    const [paying, setPaying] = useState<Props['payable'][number] | null>(null);
    const money = useMoney();
    const [warrantyOpen, setWarrantyOpen] = useState(openWarranty);
    const [busy, setBusy] = useState(false);
    const visitUnderWay = job.visits.some((v) =>
        ['on_the_way', 'in_progress'].includes(v.status),
    );
    const [visitDialog, setVisitDialog] = useState<{
        open: boolean;
        visit: Visit | null;
    }>({ open: false, visit: null });
    const [applianceDialog, setApplianceDialog] = useState<{
        open: boolean;
        appliance: ApplianceItem | null;
    }>({ open: false, appliance: null });
    const [actionError, setActionError] = useState<string | undefined>();

    const myVisit = job.visits.find((v) => v.id === myVisitId) ?? null;
    // The time the job is booked for now: the latest one not finished or cancelled.
    const current =
        [...job.visits]
            .reverse()
            .find((v) =>
                ['scheduled', 'on_the_way', 'in_progress'].includes(v.status),
            ) ?? null;
    const elapsed = useElapsedMinutes(
        myVisit?.status === 'in_progress' ? myVisit.started_at : null,
    );
    const phone = job.customer.phones[0]?.number;
    const property = job.property;
    const moneyFirst = ['invoiced', 'paid'].includes(job.status);
    const textFromPhone =
        messaging.mode === 'technician_phone' &&
        messaging.phone &&
        !messaging.opted_out;

    const act = (url: string, data: Record<string, unknown> = {}) => {
        setBusy(true);
        router.post(url, data as never, {
            preserveScroll: true,
            onSuccess: () => setActionError(undefined),
            onError: (errors) =>
                setActionError(Object.values(errors)[0] as string),
            onFinish: () => setBusy(false),
        });
    };

    const [deleteOpen, setDeleteOpen] = useState(false);
    const remove = () => setDeleteOpen(true);

    const removeVisit = (visit: Visit) => {
        if (confirm(t('jobs.confirm_delete_visit'))) {
            router.delete(destroyVisit(visit.id).url, {
                preserveScroll: true,
            });
        }
    };

    const onMyWayNow = (visit: Visit) => {
        // From the technician's phone: the messages app opens with the text ready; the status change records
        // the text in one request.
        const sms = textFromPhone
            ? { to: messaging.phone!, body: messaging.texts.on_my_way }
            : null;
        act(onMyWay(visit.id).url, sms ? { phone_sms: sms } : {});
        if (sms) {
            openSmsApp(sms.to, sms.body);
        }
    };

    // The bottom bar: the next step for the job's status (UX-REDESIGN §6.2), primary first.
    const bar: BarAction[] = (() => {
        if (myVisit && job.allows_visit_work) {
            if (myVisit.status === 'scheduled') {
                return [
                    {
                        key: 'on-my-way',
                        label: t('jobs.actions.on_my_way'),
                        icon: <Car />,
                        onClick: () => onMyWayNow(myVisit),
                    },
                    {
                        key: 'start',
                        label: t('jobs.actions.start'),
                        icon: <Play />,
                        onClick: () => act(start(myVisit.id).url),
                    },
                ];
            }
            if (myVisit.status === 'on_the_way') {
                return [
                    {
                        key: 'start',
                        label: t('jobs.actions.start'),
                        icon: <Play />,
                        onClick: () => act(start(myVisit.id).url),
                    },
                ];
            }
            if (myVisit.status === 'in_progress') {
                return [
                    {
                        key: 'complete',
                        label: t('jobs.actions.complete'),
                        icon: <CheckCircle2 />,
                        href: finishScreen(myVisit.id),
                    },
                    {
                        key: 'parts',
                        label: t('jobs.actions.waiting_for_parts'),
                        icon: <Package />,
                        href: finishScreen(myVisit.id, {
                            query: { result: 'parts' },
                        }),
                    },
                ];
            }
        }

        const unpaid = job.invoices.find((i) =>
            payable.some((p) => p.id === i.id),
        );
        if (payable.length > 0) {
            return [
                {
                    key: 'pay',
                    // One invoice owed: its balance on the button.
                    label:
                        payable.length === 1
                            ? `${t('jobs.actions.take_payment')} · ${money(payable[0].balance, payable[0].currency)}`
                            : t('jobs.actions.take_payment'),
                    icon: <Banknote />,
                    onClick: () => setPaying(payable[0]),
                },
                ...(unpaid
                    ? [
                          {
                              key: 'send-invoice',
                              label: t('jobs.actions.send_invoice'),
                              icon: <Send />,
                              href: showInvoice(unpaid.id),
                          },
                      ]
                    : []),
            ];
        }

        if (job.status === 'waiting_for_parts' && can.update && !current) {
            return [
                {
                    key: 'schedule',
                    label: t('jobs.actions.schedule_next'),
                    icon: <CalendarPlus />,
                    onClick: () => setVisitDialog({ open: true, visit: null }),
                },
            ];
        }

        if (job.status === 'completed' && can.work) {
            return [
                {
                    key: 'invoice',
                    label: t('billing.new_invoice'),
                    icon: <Receipt />,
                    href: createInvoice(job.id),
                },
                {
                    key: 'estimate',
                    label: t('billing.new_estimate'),
                    icon: <FileText />,
                    href: createEstimate(job.id),
                },
            ];
        }

        if (job.status === 'paid' && can.close && !job.outcome) {
            return [
                {
                    key: 'close',
                    label: t('jobs.actions.complete_job'),
                    icon: <Flag />,
                    onClick: () => setCloseOpen(true),
                },
            ];
        }

        return [];
    })();

    const subtitle = (() => {
        if (myVisit?.status === 'in_progress' && myVisit.started_at) {
            return t('jobs.subtitle.started', {
                time: time.time(myVisit.started_at),
                elapsed: formatMinutes(elapsed ?? 0, t),
            });
        }
        if (current) {
            return [
                time.isToday(current.scheduled_start)
                    ? t('jobs.tabs.today')
                    : time.day(current.scheduled_start),
                time.shortRange(current.scheduled_start, current.scheduled_end),
                current.assignees.map((a) => a.name).join(', '),
            ]
                .filter(Boolean)
                .join(' · ');
        }
        if (job.completed_at) {
            return [
                t('jobs.subtitle.completed', {
                    time: time.dateTime(job.completed_at),
                }),
                job.minutes_on_job > 0 && formatMinutes(job.minutes_on_job, t),
            ]
                .filter(Boolean)
                .join(' · ');
        }

        return job.visit_type_label;
    })();

    const documents = (
        <Section
            id="documents"
            title={t('billing.section')}
            action={
                can.work && (
                    <div className="flex gap-1">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={createEstimate(job.id)}>
                                <Plus /> {t('billing.new_estimate')}
                            </Link>
                        </Button>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={createInvoice(job.id)}>
                                <Plus /> {t('billing.new_invoice')}
                            </Link>
                        </Button>
                    </div>
                )
            }
        >
            {job.estimates.length + job.invoices.length === 0 ? (
                <p className="da-card p-4 text-sm text-muted-foreground">
                    {t('billing.empty')}
                </p>
            ) : (
                <DocumentList documents={[...job.invoices, ...job.estimates]} />
            )}
            {/* Several invoices with money owed: Mark as paid for each (the bar takes the first). */}
            {payable.length > 1 &&
                payable.map((invoice) => (
                    <Button
                        key={invoice.id}
                        variant="outline"
                        className="w-full"
                        onClick={() => setPaying(invoice)}
                    >
                        <Banknote />
                        {t('payments.mark_paid_invoice', {
                            number: invoice.number,
                            amount: money(invoice.balance, invoice.currency),
                        })}
                    </Button>
                ))}
            {costs && <JobCostsSection jobId={job.id} costs={costs} />}
        </Section>
    );

    const schedule = (
        <Section title={t('jobs.sections.visits')}>
            {current ? (
                <div className="da-card space-y-1 p-3">
                    <div className="flex items-start gap-2">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {time.window(
                                    current.scheduled_start,
                                    current.scheduled_end,
                                )}
                                {current.strict_arrival && <StrictBadge />}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {current.assignees.length > 0
                                    ? current.assignees
                                          .map((a) => a.name)
                                          .join(', ')
                                    : t('jobs.unassigned')}
                                {current.estimated_duration_minutes &&
                                    ` · ${t('jobs.estimated_on_site', { time: formatMinutes(current.estimated_duration_minutes, t) })}`}
                            </div>
                        </div>
                        {can.update && (
                            <div className="flex">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('jobs.edit_visit')}
                                    onClick={() =>
                                        setVisitDialog({
                                            open: true,
                                            visit: current,
                                        })
                                    }
                                >
                                    <Pencil />
                                </Button>
                                {current.status === 'scheduled' && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('jobs.delete_visit')}
                                        onClick={() => removeVisit(current)}
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                    {(current.on_the_way_at || current.started_at) && (
                        <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {(['on_the_way_at', 'started_at'] as const).map(
                                (field) =>
                                    current[field] && (
                                        <div key={field}>
                                            <dt className="inline">
                                                {t(`jobs.visit_times.${field}`)}
                                                :{' '}
                                            </dt>
                                            <dd className="inline">
                                                {time.time(current[field])}
                                            </dd>
                                        </div>
                                    ),
                            )}
                        </dl>
                    )}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {t('jobs.no_visits')}
                </p>
            )}
            {!current &&
                can.update &&
                !['cancelled', 'invoiced', 'paid'].includes(job.status) && (
                    <Button
                        variant="outline"
                        className="w-full"
                        onClick={() =>
                            setVisitDialog({ open: true, visit: null })
                        }
                    >
                        <CalendarPlus />{' '}
                        {job.visits.length > 0
                            ? t('jobs.schedule_again')
                            : t('jobs.add_visit')}
                    </Button>
                )}
        </Section>
    );

    return (
        <>
            <Head title={t('jobs.job_number', { number: job.number })} />

            <ScreenHeader
                title={`#${job.number} · ${job.job_type_label}`}
                subtitle={subtitle}
                back={index()}
                actions={
                    <ActionSheet
                        title={t('jobs.job_number', { number: job.number })}
                        items={[
                            can.close &&
                                !job.outcome &&
                                !visitUnderWay && {
                                    key: 'close',
                                    label: t('jobs.close.action'),
                                    hint: t('jobs.close.with_reason'),
                                    icon: <Flag />,
                                    onSelect: () => setCloseOpen(true),
                                },
                            statusOptions.length > 0 && {
                                key: 'status',
                                label: t('jobs.change_status'),
                                icon: <ListChecks />,
                                onSelect: () => setStatusOpen(true),
                            },
                            warrantyLines.length > 0 &&
                                can.work && {
                                    key: 'warranty',
                                    label: t('jobs.warranty.review'),
                                    icon: <ShieldCheck />,
                                    onSelect: () => setWarrantyOpen(true),
                                },
                            can.update && {
                                key: 'edit',
                                label: t('jobs.edit_job'),
                                icon: <Pencil />,
                                onSelect: () => router.visit(edit(job.id).url),
                            },
                            'separator' as const,
                            can.delete && {
                                key: 'delete',
                                label: t('jobs.delete'),
                                icon: <Trash2 />,
                                destructive: true,
                                onSelect: remove,
                            },
                        ]}
                    />
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-5 p-4 sm:p-6">
                {/* Phone: jump to a part of the page (UX-REDESIGN §7.5). */}
                <nav
                    aria-label={t('jobs.job_number', { number: job.number })}
                    className="sticky top-0 z-20 -mx-4 -mt-4 flex [scrollbar-width:none] gap-2 overflow-x-auto bg-background/90 px-4 py-2 backdrop-blur md:hidden"
                    data-test="job-anchors"
                >
                    {(
                        [
                            ['overview', 'overview'],
                            ['work', 'work'],
                            ['documents', 'money'],
                            ['activity', 'activity'],
                        ] as const
                    ).map(([target, label]) => (
                        <button
                            key={target}
                            type="button"
                            className="da-chip da-press inline-flex h-9 shrink-0 items-center rounded-full px-3.5 text-sm font-medium"
                            onClick={() => {
                                const element = document.getElementById(target);
                                if (element instanceof HTMLDetailsElement) {
                                    element.open = true;
                                }
                                element?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start',
                                });
                            }}
                        >
                            {t(`jobs.anchors.${label}`)}
                        </button>
                    ))}
                </nav>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        status={job.status}
                        label={job.status_label}
                        className="h-7 px-3 text-sm"
                    />
                    <span className="da-badge rounded-full px-2.5 py-0.5 text-xs font-medium">
                        {job.visit_type_label}
                    </span>
                </div>

                {job.outcome && job.outcome_label && (
                    <OutcomeCard
                        outcome={job.outcome}
                        label={job.outcome_label}
                        reason={job.outcome_reason}
                        note={job.outcome_note}
                        closedAt={job.closed_at}
                        closedBy={job.closed_by}
                    />
                )}

                <FollowUpLinks
                    visitTypeLabel={job.visit_type_label}
                    previous={job.previous_job}
                    followUps={job.follow_ups}
                />

                {myVisit?.strict_arrival && myVisit.status === 'scheduled' && (
                    <p className="flex items-center gap-2 rounded-lg bg-red-600 p-3 text-sm font-semibold text-white">
                        <AlarmClock className="size-5 shrink-0" />
                        {t('jobs.strict.banner', {
                            window: time.window(
                                myVisit.scheduled_start,
                                myVisit.scheduled_end,
                            ),
                        })}
                    </p>
                )}

                {/* With an invoice out, money comes first. */}
                {moneyFirst && documents}

                {/* Customer: name, address, the gate code large (needed at the door), notes, Navigate · Call · Text. */}
                <section
                    id="overview"
                    className="da-card scroll-mt-28 space-y-3 p-4"
                    data-test="job-customer"
                >
                    <div className="flex items-start gap-3">
                        {can.updateCustomer ? (
                            <CustomerAvatarPicker
                                customerId={job.customer.id}
                                icon={job.customer.avatar_icon}
                                style={job.customer.avatar_style}
                                name={job.customer.display_name}
                                size="lg"
                            />
                        ) : (
                            <CustomerAvatar
                                icon={job.customer.avatar_icon}
                                name={job.customer.display_name}
                                size="lg"
                            />
                        )}
                        <div className="min-w-0 flex-1">
                            {can.viewCustomer ? (
                                <Link
                                    href={showCustomer(job.customer.id)}
                                    className="text-lg font-semibold underline-offset-4 hover:underline"
                                >
                                    {job.customer.display_name}
                                </Link>
                            ) : (
                                <span className="text-lg font-semibold">
                                    {job.customer.display_name}
                                </span>
                            )}
                            <p className="text-sm leading-relaxed text-muted-foreground">
                                {property.full_address ||
                                    t('jobs.quick.address_pending')}
                            </p>
                            {property.full_address &&
                                (!property.line1 || !property.city) && (
                                    <p className="mt-1 text-sm text-amber-700 dark:text-amber-400">
                                        {t('jobs.quick.address_pending')}
                                    </p>
                                )}
                        </div>
                    </div>

                    {property.gate_code && (
                        <div
                            className="da-soft flex items-center gap-3 rounded-2xl px-3 py-2"
                            data-test="gate-code"
                        >
                            <KeyRound className="size-5 shrink-0" />
                            <span>
                                <span className="block text-xs font-medium tracking-wide uppercase opacity-80">
                                    {t('jobs.gate_label')}
                                </span>
                                <span className="text-xl font-bold tabular-nums select-all">
                                    {property.gate_code}
                                </span>
                            </span>
                        </div>
                    )}
                    {(property.access_notes || property.site_contact_name) && (
                        <div className="space-y-1 text-sm">
                            {property.site_contact_name && (
                                <div className="flex items-center gap-2">
                                    <User className="size-4" />
                                    {t('properties.site_contact')}:{' '}
                                    {property.site_contact_name}
                                </div>
                            )}
                            {property.access_notes && (
                                <p className="whitespace-pre-line">
                                    {property.access_notes}
                                </p>
                            )}
                        </div>
                    )}

                    <CustomerNotes notes={job.customer.notes} />

                    <div className="grid auto-cols-fr grid-flow-col gap-2">
                        {property.line1 && property.city && (
                            <Button asChild variant="outline" className="px-2">
                                <a
                                    href={mapsUrl(property.full_address)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Navigation /> {t('jobs.navigate')}
                                </a>
                            </Button>
                        )}
                        {phone && (
                            <Button asChild variant="outline" className="px-2">
                                <a href={telUrl(phone)}>
                                    <Phone /> {t('jobs.call')}
                                </a>
                            </Button>
                        )}
                        {phone &&
                            (textFromPhone ? (
                                <Button
                                    variant="outline"
                                    className="px-2"
                                    onClick={() =>
                                        openOnPhone(
                                            job.id,
                                            'general',
                                            messaging.phone!,
                                            messaging.texts.general,
                                        )
                                    }
                                >
                                    <MessageSquare /> {t('jobs.text')}
                                </Button>
                            ) : (
                                messaging.mode !== 'automatic' && (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="px-2"
                                    >
                                        <a
                                            href={`sms:${phone.replace(/[^\d+]/g, '')}`}
                                        >
                                            <MessageSquare /> {t('jobs.text')}
                                        </a>
                                    </Button>
                                )
                            ))}
                    </div>
                    {property.site_contact_phone && (
                        <Button asChild variant="outline" className="w-full">
                            <a href={telUrl(property.site_contact_phone)}>
                                <Phone />{' '}
                                {t('jobs.call_on_site', {
                                    name:
                                        property.site_contact_name ??
                                        phoneText(property.site_contact_phone),
                                })}
                            </a>
                        </Button>
                    )}

                    {/* Other numbers, e.g. the second person of a couple: Call and SMS for each. */}
                    {job.customer.phones.length > 1 && (
                        <ul className="space-y-2 text-sm">
                            {job.customer.phones.slice(1).map((p) => (
                                <li
                                    key={p.id}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-semibold">
                                            {p.contact_name ??
                                                phoneText(p.number)}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {[
                                                p.contact_name &&
                                                    phoneText(p.number),
                                                p.label_text,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    <IconButton label={t('jobs.call')} asChild>
                                        <a href={telUrl(p.number)}>
                                            <Phone />
                                        </a>
                                    </IconButton>
                                    <IconButton label={t('jobs.sms')} asChild>
                                        <a href={smsUrl(p.number, '')}>
                                            <MessageSquare />
                                        </a>
                                    </IconButton>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* Problem and notes for the team */}
                {(job.description || job.notes) && (
                    <Section title={t('jobs.sections.problem')}>
                        <div className="da-card space-y-2 p-4">
                            {job.description && (
                                <p className="whitespace-pre-line">
                                    {job.description}
                                </p>
                            )}
                            {job.notes && (
                                <div
                                    className={cn(
                                        job.description && 'border-t pt-2',
                                    )}
                                >
                                    <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                        {t('jobs.fields.notes')}
                                    </h3>
                                    <p className="text-sm whitespace-pre-line">
                                        {job.notes}
                                    </p>
                                </div>
                            )}
                        </div>
                    </Section>
                )}

                <BringList
                    jobId={job.id}
                    items={job.bring_items}
                    canTick={can.work}
                />

                {/* Appliances */}
                <div id="work" className="scroll-mt-28" aria-hidden="true" />
                {showAppliances && (
                    <Section
                        title={t('jobs.sections.appliances')}
                        action={
                            can.work && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        setApplianceDialog({
                                            open: true,
                                            appliance: null,
                                        })
                                    }
                                >
                                    <Plus /> {t('jobs.appliance.add')}
                                </Button>
                            )
                        }
                    >
                        {job.appliances.length === 0 ? (
                            <p className="da-card p-4 text-sm text-muted-foreground">
                                {t('appliances.empty')}
                            </p>
                        ) : (
                            <ul className="da-card divide-y overflow-hidden">
                                {job.appliances.map((a) => (
                                    <li
                                        key={a.id}
                                        className="flex min-h-14 items-center gap-3 px-3 py-2"
                                    >
                                        <ApplianceImage
                                            type={a.type}
                                            className="size-14"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                                {a.removed ? (
                                                    <span className="text-muted-foreground line-through">
                                                        {applianceTitle(a)}
                                                    </span>
                                                ) : (
                                                    <Link
                                                        href={showAppliance(
                                                            a.id,
                                                        )}
                                                        className="underline-offset-4 hover:underline"
                                                    >
                                                        {applianceTitle(a)}
                                                    </Link>
                                                )}
                                                {a.under_warranty && (
                                                    <ShieldCheck
                                                        className="size-4 text-emerald-600"
                                                        aria-label={t(
                                                            'appliances.under_warranty',
                                                        )}
                                                    />
                                                )}
                                            </div>
                                            <div className="text-xs break-all text-muted-foreground select-all">
                                                {[
                                                    a.model_number &&
                                                        t('appliances.model', {
                                                            model: a.model_number,
                                                        }),
                                                    a.serial_number &&
                                                        t('appliances.serial', {
                                                            serial: a.serial_number,
                                                        }),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                        </div>
                                        {can.work && !a.removed && (
                                            <RatingPlateButton
                                                jobId={job.id}
                                                applianceId={a.id}
                                                url={a.rating_plate_url}
                                            />
                                        )}
                                        {can.work && !a.removed && (
                                            <IconButton
                                                variant="plain"
                                                label={t('jobs.appliance.edit')}
                                                onClick={() =>
                                                    setApplianceDialog({
                                                        open: true,
                                                        appliance: a,
                                                    })
                                                }
                                            >
                                                <Pencil />
                                            </IconButton>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Section>
                )}

                <PhotoSection
                    jobId={job.id}
                    visitId={myVisitId}
                    photos={job.photos}
                    kinds={photoKinds}
                    canAdd={can.work}
                />

                {can.work ? (
                    <WorkDone jobId={job.id} notes={job.tech_notes} />
                ) : (
                    <Section title={t('jobs.sections.tech_notes')}>
                        <p className="text-sm whitespace-pre-line text-muted-foreground">
                            {job.tech_notes ?? '—'}
                        </p>
                    </Section>
                )}

                {!moneyFirst && documents}

                {/* The signature is taken on the invoice; the job only shows it once signed. */}
                {job.signature && (
                    <SignatureSection
                        jobId={job.id}
                        signature={job.signature}
                        customerName={job.customer.display_name}
                        canSign={false}
                    />
                )}

                {/* The office plans the schedule here; for the field it is part of Activity. */}
                {can.update && schedule}

                {/* Activity, folded: texts, the schedule, the status history and the details. */}
                <details
                    id="activity"
                    className="da-card group scroll-mt-28"
                    data-test="job-activity"
                >
                    <summary className="flex min-h-14 cursor-pointer list-none items-center gap-3 px-4 [&::-webkit-details-marker]:hidden">
                        <History className="size-5 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1">
                            <span className="block font-semibold">
                                {t('jobs.sections.activity')}
                            </span>
                            <span className="block truncate text-sm text-muted-foreground">
                                {t('jobs.activity_hint')}
                            </span>
                        </span>
                        <ChevronDown className="size-5 shrink-0 transition-transform group-open:rotate-180" />
                    </summary>
                    <div className="space-y-5 border-t p-4">
                        <JobMessagingSection
                            jobId={job.id}
                            messaging={messaging}
                            canWork={can.work}
                        />

                        {!can.update && schedule}

                        <Section title={t('jobs.sections.history')}>
                            <ol className="space-y-2 border-l pl-4">
                                {job.history.map((h) => (
                                    <li key={h.id} className="text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            {h.from_label === null ? (
                                                <span className="font-medium">
                                                    {t(
                                                        'jobs.history_entry.created',
                                                    )}
                                                </span>
                                            ) : null}
                                            <StatusBadge
                                                status={h.to}
                                                label={h.to_label}
                                            />
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {[
                                                h.created_at &&
                                                    time.dateTime(h.created_at),
                                                h.user,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                        {h.note && (
                                            <p className="text-xs whitespace-pre-line">
                                                {h.note}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </Section>

                        <Section title={t('jobs.sections.details')}>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                {(
                                    [
                                        [
                                            'jobs.fields.job_type',
                                            job.job_type_label,
                                        ],
                                        [
                                            'jobs.fields.visit_type',
                                            job.visit_type_label,
                                        ],
                                        ['jobs.fields.brand', job.brand],
                                        [
                                            'jobs.fields.lead_source',
                                            job.lead_source_label,
                                        ],
                                        [
                                            'jobs.details.created',
                                            job.created_at &&
                                                time.dateTime(job.created_at),
                                        ],
                                        [
                                            'jobs.details.time_on_job',
                                            job.minutes_on_job > 0 &&
                                                formatMinutes(
                                                    job.minutes_on_job,
                                                    t,
                                                ),
                                        ],
                                    ] as [string, string | null | false][]
                                )
                                    .filter(([, value]) => value)
                                    .map(([label, value]) => (
                                        <Fragment key={label}>
                                            <dt className="text-muted-foreground">
                                                {t(label)}
                                            </dt>
                                            <dd>{value}</dd>
                                        </Fragment>
                                    ))}
                            </dl>
                        </Section>

                        {auth.can?.viewChangeLog && (
                            <RecordHistory type="job" id={job.id} />
                        )}
                    </div>
                </details>
            </div>

            {bar.length > 0 && (
                <StickyActionBar>
                    {bar.map((action, position) => {
                        const variant = position === 0 ? 'default' : 'outline';
                        const content = (
                            <>
                                {action.icon} {action.label}
                            </>
                        );

                        return action.href ? (
                            <Button
                                key={action.key}
                                asChild
                                variant={variant}
                                size="lg"
                                data-test={`bar-${action.key}`}
                            >
                                <Link href={action.href}>{content}</Link>
                            </Button>
                        ) : (
                            <Button
                                key={action.key}
                                variant={variant}
                                size="lg"
                                loading={busy && position === 0}
                                disabled={busy}
                                data-test={`bar-${action.key}`}
                                onClick={action.onClick}
                            >
                                {content}
                            </Button>
                        );
                    })}
                </StickyActionBar>
            )}
            {actionError && (
                <div className="fixed inset-x-3 bottom-24 z-50 md:static">
                    <InputError message={actionError} />
                </div>
            )}

            {can.delete && (
                <ConfirmDialog
                    open={deleteOpen}
                    onOpenChange={setDeleteOpen}
                    destructive
                    title={t('jobs.delete_confirm_title', {
                        number: job.number,
                    })}
                    description={t('jobs.delete_confirm_text', { days: 30 })}
                    confirmLabel={t('jobs.delete')}
                    processing={busy}
                    onConfirm={() => {
                        setBusy(true);
                        router.delete(destroy(job.id).url, {
                            onFinish: () => {
                                setBusy(false);
                                setDeleteOpen(false);
                            },
                        });
                    }}
                />
            )}

            {statusOptions.length > 0 && (
                <StatusDialog
                    open={statusOpen}
                    onOpenChange={setStatusOpen}
                    jobId={job.id}
                    current={job.status}
                    options={statusOptions}
                    cancelReasons={closureReasons.cancelled}
                />
            )}

            {can.close && (
                <FinishDialog
                    open={closeOpen}
                    onOpenChange={setCloseOpen}
                    jobId={job.id}
                    reasons={closureReasons}
                    callback={callback}
                />
            )}
            {can.work && warrantyLines.length > 0 && (
                <WarrantyDialog
                    open={warrantyOpen}
                    onOpenChange={setWarrantyOpen}
                    jobId={job.id}
                    lines={warrantyLines}
                    units={warrantyUnits}
                />
            )}

            {paying && (
                <PaymentDialog
                    open={paying !== null}
                    onOpenChange={(open) => !open && setPaying(null)}
                    invoiceId={paying.id}
                    balance={paying.balance}
                    currency={paying.currency}
                    methods={paymentMethods}
                    today={today}
                />
            )}

            {can.update && (
                <VisitDialog
                    open={visitDialog.open}
                    onOpenChange={(open) =>
                        setVisitDialog((s) => ({ ...s, open }))
                    }
                    jobId={job.id}
                    visit={visitDialog.visit}
                    today={today}
                    assignableUsers={assignableUsers}
                />
            )}

            {can.work && (
                <JobApplianceDialog
                    open={applianceDialog.open}
                    onOpenChange={(open) =>
                        setApplianceDialog((s) => ({ ...s, open }))
                    }
                    jobId={job.id}
                    appliance={applianceDialog.appliance}
                    otherAppliances={otherAppliances}
                    applianceTypes={applianceTypes}
                    manufacturers={manufacturers}
                    address={property.full_address}
                />
            )}
        </>
    );
}

JobShow.layout = {
    breadcrumbs: [{ title: 'jobs.title', href: index() }],
};
