import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CalendarPlus,
    Car,
    CheckCircle2,
    History,
    KeyRound,
    MessageSquare,
    Navigation,
    Pencil,
    Phone,
    Play,
    Plus,
    Receipt,
    ShieldCheck,
    Trash2,
    User,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { DocumentList } from '@/components/billing/document-list';
import type { DocumentRow } from '@/components/billing/types';
import { mapsUrl, telUrl } from '@/components/customers/types';
import type { PropertyData } from '@/components/customers/types';
import InputError from '@/components/input-error';
import { ChecklistSection } from '@/components/jobs/checklist-section';
import type { ChecklistItemData } from '@/components/jobs/checklist-section';
import { FinishDialog } from '@/components/jobs/finish-dialog';
import { JobApplianceDialog } from '@/components/jobs/job-appliance-dialog';
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
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { formatMinutes, useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { show as showAppliance } from '@/routes/appliances';
import { show as showCustomer } from '@/routes/customers';
import { create as createEstimate } from '@/routes/estimates';
import { create as createInvoice } from '@/routes/invoices';
import { destroy, edit, index, techNotes } from '@/routes/jobs';
import { destroy as destroyVisit, onMyWay, start } from '@/routes/visits';
import type { Option } from '@/types';

type Job = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    job_type_label: string;
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
        phones: { id: number; number: string; label_text: string }[];
    };
    property: PropertyData & { full_address: string };
    appliances: (ApplianceItem & { rating_plate_url: string | null })[];
    visits: Visit[];
    photos: JobPhotoData[];
    checklist: ChecklistItemData[];
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
        viewCustomer: boolean;
    };
    statusOptions: Option[];
    assignableUsers: Assignable[];
    otherAppliances: ApplianceItem[];
    applianceTypes: Option[];
    manufacturers: string[];
    today: string;
    photoKinds: Option[];
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
}: Props) {
    const t = useTrans();
    const time = useCompanyTime();
    const [statusOpen, setStatusOpen] = useState(false);
    const [finishOpen, setFinishOpen] = useState(false);
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
    const elapsed = useElapsedMinutes(
        myVisit?.status === 'in_progress' ? myVisit.started_at : null,
    );
    const phone = job.customer.phones[0]?.number;
    const property = job.property;

    const notesForm = useForm({ tech_notes: job.tech_notes ?? '' });

    const saveNotes = (e: FormEvent) => {
        e.preventDefault();
        notesForm.put(techNotes(job.id).url, { preserveScroll: true });
    };

    const act = (url: string) =>
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => setActionError(undefined),
                onError: (errors) =>
                    setActionError(Object.values(errors)[0] as string),
            },
        );

    const remove = () => {
        if (confirm(t('jobs.confirm_delete', { number: job.number }))) {
            router.delete(destroy(job.id).url);
        }
    };

    const removeVisit = (visit: Visit) => {
        if (confirm(t('jobs.confirm_delete_visit'))) {
            router.delete(destroyVisit(visit.id).url, {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title={t('jobs.job_number', { number: job.number })} />

            <div
                className={
                    myVisit && job.allows_visit_work
                        ? 'max-w-3xl space-y-6 p-4 pb-28 md:pb-4'
                        : 'max-w-3xl space-y-6 p-4'
                }
            >
                <PageHeader
                    title={t('jobs.job_number', { number: job.number })}
                    description={[
                        job.job_type_label,
                        job.brand,
                        job.lead_source_label,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        can.update && (
                            <Button variant="outline" asChild>
                                <Link href={edit(job.id)}>
                                    <Pencil /> {t('common.edit')}
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge
                        status={job.status}
                        label={job.status_label}
                        className="px-3 py-1 text-sm"
                    />
                    {job.minutes_on_job > 0 && (
                        <span className="text-sm text-muted-foreground">
                            {t('jobs.time_on_job', {
                                time: formatMinutes(job.minutes_on_job, t),
                            })}
                        </span>
                    )}
                    {statusOptions.length > 0 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setStatusOpen(true)}
                        >
                            {t('jobs.change_status')}
                        </Button>
                    )}
                </div>

                {/* Field actions for the current user's visit */}
                {myVisit && job.allows_visit_work && (
                    <div className="fixed inset-x-0 bottom-0 z-20 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:static md:rounded-lg md:border md:shadow-none">
                        <div className="mx-auto flex max-w-3xl flex-col gap-2">
                            <div className="flex items-center justify-between text-sm">
                                <span className="font-medium">
                                    {time.window(
                                        myVisit.scheduled_start,
                                        myVisit.scheduled_end,
                                    )}
                                </span>
                                {elapsed !== null && (
                                    <span className="font-mono text-muted-foreground tabular-nums">
                                        {formatMinutes(elapsed, t)}
                                    </span>
                                )}
                            </div>
                            {myVisit.status === 'scheduled' && (
                                <div className="grid grid-cols-2 gap-2">
                                    <Button
                                        size="lg"
                                        className="h-12"
                                        onClick={() =>
                                            act(onMyWay(myVisit.id).url)
                                        }
                                    >
                                        <Car /> {t('jobs.actions.on_my_way')}
                                    </Button>
                                    <Button
                                        size="lg"
                                        variant="outline"
                                        className="h-12"
                                        onClick={() =>
                                            act(start(myVisit.id).url)
                                        }
                                    >
                                        <Play /> {t('jobs.actions.start')}
                                    </Button>
                                </div>
                            )}
                            {myVisit.status === 'on_the_way' && (
                                <Button
                                    size="lg"
                                    className="h-12"
                                    onClick={() => act(start(myVisit.id).url)}
                                >
                                    <Play /> {t('jobs.actions.start')}
                                </Button>
                            )}
                            {myVisit.status === 'in_progress' && (
                                <Button
                                    size="lg"
                                    className="h-12"
                                    onClick={() => setFinishOpen(true)}
                                >
                                    <CheckCircle2 />{' '}
                                    {t('jobs.actions.finish_title')}
                                </Button>
                            )}
                            <InputError message={actionError} />
                        </div>
                    </div>
                )}

                {/* Customer and address */}
                <section className="space-y-3 rounded-lg border p-4">
                    <div className="flex items-start justify-between gap-2">
                        <div>
                            <h2 className="text-xs text-muted-foreground">
                                {t('jobs.sections.customer')}
                            </h2>
                            {can.viewCustomer ? (
                                <Link
                                    href={showCustomer(job.customer.id)}
                                    className="text-base font-medium underline-offset-4 hover:underline"
                                >
                                    {job.customer.display_name}
                                </Link>
                            ) : (
                                <span className="text-base font-medium">
                                    {job.customer.display_name}
                                </span>
                            )}
                            <p className="text-sm">{property.full_address}</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                        <Button asChild size="lg" variant="outline">
                            <a
                                href={mapsUrl(property.full_address)}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Navigation /> {t('jobs.navigate')}
                            </a>
                        </Button>
                        {phone && (
                            <Button asChild size="lg" variant="outline">
                                <a href={telUrl(phone)}>
                                    <Phone /> {t('jobs.call')}
                                </a>
                            </Button>
                        )}
                        {phone && (
                            <Button asChild size="lg" variant="outline">
                                <a href={`sms:${phone.replace(/[^\d+]/g, '')}`}>
                                    <MessageSquare /> {t('jobs.text')}
                                </a>
                            </Button>
                        )}
                        {property.site_contact_phone && (
                            <Button asChild size="lg" variant="outline">
                                <a
                                    href={telUrl(property.site_contact_phone)}
                                    aria-label={t(
                                        'properties.call_site_contact',
                                    )}
                                >
                                    <Phone />{' '}
                                    {property.site_contact_name ??
                                        property.site_contact_phone}
                                </a>
                            </Button>
                        )}
                    </div>

                    {job.customer.phones.length > 1 && (
                        <ul className="space-y-1 text-sm">
                            {job.customer.phones.slice(1).map((p) => (
                                <li key={p.id}>
                                    <a
                                        href={telUrl(p.number)}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {p.number}
                                    </a>{' '}
                                    <span className="text-xs text-muted-foreground">
                                        {p.label_text}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {(property.gate_code ||
                        property.access_notes ||
                        property.site_contact_name) && (
                        <div className="space-y-1 rounded-md bg-muted/50 p-3 text-sm">
                            {property.gate_code && (
                                <div className="flex items-center gap-2 font-medium">
                                    <KeyRound className="size-4" />
                                    {t('jobs.gate_code', {
                                        code: property.gate_code,
                                    })}
                                </div>
                            )}
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
                </section>

                {/* Problem and notes */}
                {(job.description || job.notes) && (
                    <section className="space-y-3 rounded-lg border p-4">
                        {job.description && (
                            <div>
                                <h2 className="text-xs text-muted-foreground">
                                    {t('jobs.fields.description')}
                                </h2>
                                <p className="whitespace-pre-line">
                                    {job.description}
                                </p>
                            </div>
                        )}
                        {job.notes && (
                            <div>
                                <h2 className="text-xs text-muted-foreground">
                                    {t('jobs.fields.notes')}
                                </h2>
                                <p className="text-sm whitespace-pre-line">
                                    {job.notes}
                                </p>
                            </div>
                        )}
                    </section>
                )}

                {/* Appliances */}
                <section className="space-y-2">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-medium">
                            {t('jobs.sections.appliances')}
                        </h2>
                        {can.work && (
                            <Button
                                variant="outline"
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
                        )}
                    </div>
                    {job.appliances.length === 0 ? (
                        <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                            {t('appliances.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {job.appliances.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex min-h-14 items-center gap-2 px-3 py-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                            {a.removed ? (
                                                <span className="text-muted-foreground line-through">
                                                    {applianceTitle(a)}
                                                </span>
                                            ) : (
                                                <Link
                                                    href={showAppliance(a.id)}
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
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t(
                                                'jobs.appliance.edit',
                                            )}
                                            onClick={() =>
                                                setApplianceDialog({
                                                    open: true,
                                                    appliance: a,
                                                })
                                            }
                                        >
                                            <Pencil />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <ChecklistSection
                    jobId={job.id}
                    items={job.checklist}
                    canTick={can.work}
                />

                <PhotoSection
                    jobId={job.id}
                    visitId={myVisitId}
                    photos={job.photos}
                    kinds={photoKinds}
                    canAdd={can.work}
                />

                {/* Work done */}
                <section className="space-y-2">
                    <h2 className="text-base font-medium">
                        {t('jobs.sections.tech_notes')}
                    </h2>
                    {can.work ? (
                        <form onSubmit={saveNotes} className="space-y-2">
                            <Textarea
                                rows={4}
                                aria-label={t('jobs.fields.tech_notes')}
                                value={notesForm.data.tech_notes}
                                onChange={(e) =>
                                    notesForm.setData(
                                        'tech_notes',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('jobs.fields.tech_notes_hint')}
                            </p>
                            <InputError message={notesForm.errors.tech_notes} />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={
                                    notesForm.processing || !notesForm.isDirty
                                }
                            >
                                {t('common.save')}
                            </Button>
                        </form>
                    ) : (
                        <p className="text-sm whitespace-pre-line text-muted-foreground">
                            {job.tech_notes ?? '—'}
                        </p>
                    )}
                </section>

                <SignatureSection
                    jobId={job.id}
                    signature={job.signature}
                    customerName={job.customer.display_name}
                    canSign={can.work}
                />

                {/* Visits */}
                <section className="space-y-2">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-medium">
                            {t('jobs.sections.visits')}
                        </h2>
                        {can.update && job.status !== 'cancelled' && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setVisitDialog({ open: true, visit: null })
                                }
                            >
                                <CalendarPlus /> {t('jobs.add_visit')}
                            </Button>
                        )}
                    </div>
                    {job.visits.length === 0 ? (
                        <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                            {t('jobs.no_visits')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {job.visits.map((v) => (
                                <li key={v.id} className="space-y-1 p-3">
                                    <div className="flex items-start gap-2">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                {time.window(
                                                    v.scheduled_start,
                                                    v.scheduled_end,
                                                )}
                                                <StatusBadge
                                                    status={v.status}
                                                    label={v.status_label}
                                                />
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {v.assignees.length > 0
                                                    ? v.assignees
                                                          .map((a) => a.name)
                                                          .join(', ')
                                                    : t('jobs.unassigned')}
                                                {v.estimated_duration_minutes &&
                                                    ` · ${formatMinutes(v.estimated_duration_minutes, t)}`}
                                            </div>
                                        </div>
                                        {can.update && (
                                            <div className="flex">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-9"
                                                    aria-label={t(
                                                        'jobs.edit_visit',
                                                    )}
                                                    onClick={() =>
                                                        setVisitDialog({
                                                            open: true,
                                                            visit: v,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                                {v.status === 'scheduled' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-9"
                                                        aria-label={t(
                                                            'jobs.delete_visit',
                                                        )}
                                                        onClick={() =>
                                                            removeVisit(v)
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    {(v.on_the_way_at ||
                                        v.started_at ||
                                        v.finished_at) && (
                                        <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                            {(
                                                [
                                                    'on_the_way_at',
                                                    'started_at',
                                                    'finished_at',
                                                ] as const
                                            ).map(
                                                (field) =>
                                                    v[field] && (
                                                        <div key={field}>
                                                            <dt className="inline">
                                                                {t(
                                                                    `jobs.visit_times.${field}`,
                                                                )}
                                                                :{' '}
                                                            </dt>
                                                            <dd className="inline">
                                                                {time.time(
                                                                    v[field],
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ),
                                            )}
                                            {v.minutes_on_job !== null && (
                                                <div>
                                                    {t('jobs.time_on_job', {
                                                        time: formatMinutes(
                                                            v.minutes_on_job,
                                                            t,
                                                        ),
                                                    })}
                                                </div>
                                            )}
                                        </dl>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* Estimates and invoices */}
                <section className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-base font-medium">
                            <Receipt className="size-4" />
                            {t('billing.section')}
                        </h2>
                        {can.work && (
                            <div className="flex gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={createEstimate(job.id)}>
                                        <Plus /> {t('billing.new_estimate')}
                                    </Link>
                                </Button>
                                <Button size="sm" asChild>
                                    <Link href={createInvoice(job.id)}>
                                        <Plus /> {t('billing.new_invoice')}
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>
                    {job.estimates.length + job.invoices.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('billing.empty')}
                        </p>
                    ) : (
                        <DocumentList
                            documents={[...job.invoices, ...job.estimates]}
                        />
                    )}
                </section>

                {/* Status history */}
                <section className="space-y-2">
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        <History className="size-4" />
                        {t('jobs.sections.history')}
                    </h2>
                    <ol className="space-y-2 border-l pl-4">
                        {job.history.map((h) => (
                            <li key={h.id} className="text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    {h.from_label === null ? (
                                        <span className="font-medium">
                                            {t('jobs.history_entry.created')}
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
                </section>

                {can.delete && (
                    <Button variant="destructive" onClick={remove}>
                        <Trash2 /> {t('jobs.delete')}
                    </Button>
                )}
            </div>

            {statusOptions.length > 0 && (
                <StatusDialog
                    open={statusOpen}
                    onOpenChange={setStatusOpen}
                    jobId={job.id}
                    current={job.status}
                    options={statusOptions}
                />
            )}

            {myVisit && (
                <FinishDialog
                    open={finishOpen}
                    onOpenChange={setFinishOpen}
                    visitId={myVisit.id}
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
