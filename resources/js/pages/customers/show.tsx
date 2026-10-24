import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    KeyRound,
    Mail,
    MapPin,
    MessageSquare,
    Navigation,
    Pencil,
    Phone,
    Plus,
    ShieldCheck,
    Trash2,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { DocumentList } from '@/components/billing/document-list';
import { useMoney } from '@/components/billing/money';
import type { DocumentRow } from '@/components/billing/types';
import { ApplianceDialog } from '@/components/customers/appliance-dialog';
import { CustomerNotesEditor as NotesEditor } from '@/components/customers/customer-notes-editor';
import {
    CustomerAvatar,
    CustomerAvatarPicker,
} from '@/components/customers/customer-avatar';
import type {
    AvatarIcon,
    AvatarStyle,
} from '@/components/customers/customer-avatar';
import {
    CustomerHistory,
    CustomerSummaryTiles,
} from '@/components/customers/customer-history';
import type {
    CustomerSummary,
    HistoryRow,
} from '@/components/customers/customer-history';
import { PropertyDialog } from '@/components/customers/property-dialog';
import { mapsUrl, telUrl } from '@/components/customers/types';
import type { PropertyData } from '@/components/customers/types';
import { RecentMessages } from '@/components/messaging/recent-messages';
import type { MessageItem } from '@/components/messaging/types';
import { RecordHistory } from '@/components/record-history';
import { ScreenHeader } from '@/components/screen-header';
import { ActionSheet } from '@/components/ui/action-sheet';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { SwipeTabs } from '@/components/ui/swipe-tabs';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { smsUrl } from '@/lib/sms';
import { show as showAppliance } from '@/routes/appliances';
import { destroy, edit, index } from '@/routes/customers';
import { create as createJob, show as showJob } from '@/routes/jobs';
import { destroy as destroyProperty } from '@/routes/properties';
import type { Option } from '@/types';

type ApplianceRow = {
    id: number;
    type: string;
    type_label: string;
    manufacturer: string | null;
    model_number: string | null;
    serial_number: string | null;
    under_warranty: boolean;
};

type PropertyRow = PropertyData & {
    full_address: string;
    appliances: ApplianceRow[];
};

type Customer = {
    id: number;
    type: string;
    type_label: string;
    display_name: string;
    avatar_icon: AvatarIcon;
    avatar_style: AvatarStyle;
    first_name: string | null;
    last_name: string | null;
    company_name: string | null;
    lead_source_label: string | null;
    payment_terms_label: string | null;
    tags: string[];
    notes: string | null;
    created_at: string | null;
    billing: Record<string, string | null> | null;
    billing_address: string | null;
    phones: {
        id: number;
        label_text: string;
        contact_name: string | null;
        number: string;
        is_primary: boolean;
        sms_opted_out_at: string | null;
    }[];
    emails: {
        id: number;
        label_text: string;
        email: string;
        is_primary: boolean;
    }[];
    properties: PropertyRow[];
};

type FileRow = {
    id: number;
    url: string;
    job_id: number;
    job_number: number | null;
    kind: string;
    taken_at: string | null;
};

type Props = {
    customer: Customer;
    history: HistoryRow[];
    jobNotes: { job_id: number; number: number; notes: string }[];
    files: FileRow[];
    summary: CustomerSummary;
    estimates: DocumentRow[];
    invoices: DocumentRow[];
    canCreateJob: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    applianceTypes: Option[];
    manufacturers: string[];
    messaging: {
        mode: string;
        messages: MessageItem[];
        chat_url: string | null;
    };
};

type Tab = 'client' | 'work' | 'notes' | 'files';

/**
 * Customer card (owner's update, October 24): a main block that stays the same (name, balance, Call, Text), then
 * Client / Work / Notes / Files tabs, switched with a tap or a swipe.
 */
export default function CustomerShow({
    customer,
    history,
    jobNotes,
    files,
    summary,
    estimates,
    invoices,
    canCreateJob,
    canUpdate,
    canDelete,
    applianceTypes,
    manufacturers,
    messaging,
    countries = [],
}: Props & { countries?: Option[] }) {
    const t = useTrans();
    const time = useCompanyTime();
    const money = useMoney();
    const phoneText = usePhone();
    const canViewHistory = Boolean(usePage().props.auth.can?.viewChangeLog);
    const [tab, setTab] = useState<Tab>('client');
    const [propertyDialog, setPropertyDialog] = useState<{
        open: boolean;
        property: PropertyData | null;
    }>({ open: false, property: null });
    const [applianceFor, setApplianceFor] = useState<PropertyRow | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const primaryPhone = customer.phones[0]?.number;
    const contactPerson =
        customer.company_name &&
        [customer.first_name, customer.last_name].filter(Boolean).join(' ');
    // The person replied STOP: texting is off (SPEC §7.7).
    const smsOff = Boolean(customer.phones[0]?.sms_opted_out_at);
    const owed = summary.money.filter((row) => row.owed > 0);

    const removeProperty = (property: PropertyRow) => {
        if (confirm(t('properties.confirm_delete'))) {
            router.delete(destroyProperty(property.id).url, {
                preserveScroll: true,
            });
        }
    };

    const textButton =
        primaryPhone &&
        (smsOff ? (
            <Button
                variant="outline"
                className="px-2"
                disabled
                title={t('messages.opted_out_hint')}
            >
                <MessageSquare /> {t('customers.text')}
            </Button>
        ) : messaging.mode === 'automatic' && messaging.chat_url ? (
            /* Automatic texts: the SMS chat with the customer. */
            <Button asChild variant="outline" className="px-2">
                <Link href={messaging.chat_url} data-test="customer-text">
                    <MessageSquare /> {t('customers.text')}
                </Link>
            </Button>
        ) : (
            <Button asChild variant="outline" className="px-2">
                <a href={smsUrl(primaryPhone, '')} data-test="customer-text">
                    <MessageSquare /> {t('customers.text')}
                </a>
            </Button>
        ));

    const clientTab = (
        <div className="space-y-5" data-test="customer-tab-client">
            <section className="da-card space-y-2 p-4">
                <h2 className="text-sm font-semibold">
                    {t('customers.sections.contacts')}
                </h2>
                <ul className="space-y-1 text-sm">
                    {customer.phones.map((p) => (
                        <li
                            key={p.id}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <Phone className="size-4 text-muted-foreground" />
                            <a
                                href={telUrl(p.number)}
                                className="underline-offset-4 hover:underline"
                            >
                                {phoneText(p.number)}
                            </a>
                            <span className="text-xs text-muted-foreground">
                                {[p.contact_name, p.label_text]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                            {p.sms_opted_out_at && (
                                <span
                                    className="rounded bg-destructive/10 px-1.5 py-0.5 text-xs text-destructive"
                                    title={t('messages.opted_out_on', {
                                        date: time.date(p.sms_opted_out_at),
                                    })}
                                >
                                    {t('messages.opted_out')}
                                </span>
                            )}
                        </li>
                    ))}
                    {customer.emails.map((e) => (
                        <li key={e.id} className="flex items-center gap-2">
                            <Mail className="size-4 text-muted-foreground" />
                            <a
                                href={`mailto:${e.email}`}
                                className="break-all underline-offset-4 hover:underline"
                            >
                                {e.email}
                            </a>
                            <span className="text-xs text-muted-foreground">
                                {e.label_text}
                            </span>
                        </li>
                    ))}
                    {customer.phones.length === 0 &&
                        customer.emails.length === 0 && (
                            <li className="text-muted-foreground">
                                {t('customers.no_phones')}
                            </li>
                        )}
                </ul>
                {smsOff && (
                    <p className="text-xs text-muted-foreground">
                        {t('messages.opted_out_hint')}
                    </p>
                )}
                <div
                    className="border-t pt-2 text-sm"
                    data-test="customer-billing"
                >
                    <span className="text-xs font-medium text-muted-foreground">
                        {t('customers.fields.billing_address')}
                    </span>
                    <p>
                        {customer.billing
                            ? customer.billing_address
                            : customer.billing_address
                              ? `${customer.billing_address} · ${t('customers.billing_same')}`
                              : t('customers.billing_same')}
                    </p>
                </div>
                {customer.tags.length > 0 && (
                    <div className="flex flex-wrap gap-1 pt-1">
                        {customer.tags.map((tag) => (
                            <Badge key={tag} variant="secondary">
                                {tag}
                            </Badge>
                        ))}
                    </div>
                )}
                <p className="pt-1 text-xs text-muted-foreground">
                    {[
                        customer.lead_source_label &&
                            t('customers.details.source', {
                                source: customer.lead_source_label,
                            }),
                        customer.payment_terms_label,
                        customer.created_at &&
                            t('customers.customer_since', {
                                date: time.dateOnly(customer.created_at),
                            }),
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </section>

            <section className="space-y-3" data-test="customer-properties">
                <div className="flex items-center justify-between">
                    <h2 className="text-base font-semibold">
                        {t('customers.sections.properties')}
                    </h2>
                    {canUpdate && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setPropertyDialog({
                                    open: true,
                                    property: null,
                                })
                            }
                        >
                            <Plus /> {t('properties.add')}
                        </Button>
                    )}
                </div>

                {customer.properties.length === 0 && (
                    <p className="da-card p-4 text-sm text-muted-foreground">
                        {t('properties.empty')}
                    </p>
                )}

                {customer.properties.map((property) => (
                    <article
                        key={property.id}
                        className="da-card space-y-3 p-4"
                    >
                        <div className="flex items-start gap-2">
                            <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    {property.label && (
                                        <span className="font-medium">
                                            {property.label}
                                        </span>
                                    )}
                                    {property.is_primary && (
                                        <Badge variant="outline">
                                            {t('properties.primary')}
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm">
                                    {property.full_address}
                                </p>
                            </div>
                            {canUpdate && (
                                <div className="flex">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-11"
                                        aria-label={t('properties.edit')}
                                        onClick={() =>
                                            setPropertyDialog({
                                                open: true,
                                                property,
                                            })
                                        }
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-11"
                                        aria-label={t('properties.delete')}
                                        onClick={() => removeProperty(property)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            )}
                        </div>

                        {property.site_contact_name && (
                            <p
                                className="flex items-center gap-2 text-sm"
                                data-test="property-occupant"
                            >
                                <User className="size-4 text-muted-foreground" />
                                {t('properties.site_contact')}:{' '}
                                {property.site_contact_name}
                                {property.site_contact_phone &&
                                    ` · ${phoneText(property.site_contact_phone)}`}
                            </p>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button variant="sky" size="sm" asChild>
                                <a
                                    href={mapsUrl(property.full_address)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Navigation />{' '}
                                    {t('properties.open_in_maps')}
                                </a>
                            </Button>
                            {property.site_contact_phone && (
                                <Button variant="success" size="sm" asChild>
                                    <a
                                        href={telUrl(
                                            property.site_contact_phone,
                                        )}
                                        aria-label={t(
                                            'properties.call_site_contact',
                                        )}
                                    >
                                        <Phone /> {t('customers.call')}{' '}
                                        {property.site_contact_name ??
                                            phoneText(
                                                property.site_contact_phone,
                                            )}
                                    </a>
                                </Button>
                            )}
                        </div>

                        {(property.gate_code || property.access_notes) && (
                            <dl className="space-y-1 text-sm text-muted-foreground">
                                {property.gate_code && (
                                    <div className="flex items-center gap-2">
                                        <KeyRound className="size-4" />
                                        {t('properties.gate_code', {
                                            code: property.gate_code,
                                        })}
                                    </div>
                                )}
                                {property.access_notes && (
                                    <p className="whitespace-pre-line">
                                        {property.access_notes}
                                    </p>
                                )}
                            </dl>
                        )}

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <h3 className="text-sm font-medium">
                                    {t('customers.sections.appliances')}
                                </h3>
                                {canUpdate && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setApplianceFor(property)
                                        }
                                    >
                                        <Plus /> {t('appliances.add')}
                                    </Button>
                                )}
                            </div>
                            {property.appliances.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('appliances.empty')}
                                </p>
                            ) : (
                                <ul className="divide-y rounded-xl border">
                                    {property.appliances.map((a) => (
                                        <li key={a.id}>
                                            <Link
                                                href={showAppliance(a.id)}
                                                className="flex min-h-12 items-center gap-2 px-3 py-2 hover:bg-muted/50"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                        {[
                                                            a.manufacturer,
                                                            a.type_label,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' ')}
                                                        {a.under_warranty && (
                                                            <ShieldCheck
                                                                className="size-4 text-emerald-600"
                                                                aria-label={t(
                                                                    'appliances.under_warranty',
                                                                )}
                                                            />
                                                        )}
                                                    </div>
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        {[
                                                            a.model_number &&
                                                                t(
                                                                    'appliances.model',
                                                                    {
                                                                        model: a.model_number,
                                                                    },
                                                                ),
                                                            a.serial_number &&
                                                                t(
                                                                    'appliances.serial',
                                                                    {
                                                                        serial: a.serial_number,
                                                                    },
                                                                ),
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </div>
                                                </div>
                                                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </article>
                ))}
            </section>

            {canViewHistory && (
                <RecordHistory type="customer" id={customer.id} />
            )}
        </div>
    );

    const workTab = (
        <div className="space-y-5" data-test="customer-tab-work">
            <CustomerSummaryTiles summary={summary} />
            {canCreateJob && (
                <Button asChild className="w-full">
                    <Link
                        href={createJob({
                            query: { book: 1, customer_id: customer.id },
                        })}
                        data-test="customer-new-job"
                    >
                        <Plus /> {t('customers.new_job')}
                    </Link>
                </Button>
            )}
            <section className="space-y-3">
                <h2 className="text-base font-semibold">
                    {t('customers.sections.jobs')}
                </h2>
                <CustomerHistory
                    rows={history}
                    empty={t('customers.no_jobs')}
                />
            </section>
            <section id="documents" className="scroll-mt-24 space-y-2">
                <h2 className="text-base font-semibold">
                    {t('billing.section')}
                </h2>
                {estimates.length + invoices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('billing.empty')}
                    </p>
                ) : (
                    <DocumentList documents={[...invoices, ...estimates]} />
                )}
            </section>
            <section className="space-y-3">
                <h2 className="text-base font-semibold">
                    {t('messages.title')}
                </h2>
                <RecentMessages
                    messages={messaging.messages}
                    chatUrl={messaging.chat_url}
                    showJobLinks
                />
            </section>
        </div>
    );

    const notesTab = (
        <div className="space-y-5" data-test="customer-tab-notes">
            <NotesEditor
                customerId={customer.id}
                notes={customer.notes}
                canUpdate={canUpdate}
            />
            {jobNotes.length > 0 && (
                <section className="space-y-2">
                    <h2 className="text-base font-semibold">
                        {t('customers.job_notes')}
                    </h2>
                    <ul className="space-y-2">
                        {jobNotes.map((note) => (
                            <li key={note.job_id}>
                                <Link
                                    href={showJob(note.job_id)}
                                    className="da-card da-press block p-3 text-sm"
                                >
                                    <span className="block text-xs font-semibold text-muted-foreground">
                                        {t('customers.file_from_job', {
                                            number: note.number,
                                        })}
                                    </span>
                                    <span className="whitespace-pre-wrap">
                                        {note.notes}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );

    const filesTab = (
        <div data-test="customer-tab-files">
            {files.length === 0 ? (
                <p className="da-card p-4 text-sm text-muted-foreground">
                    {t('customers.no_files')}
                </p>
            ) : (
                <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    {files.map((file) => (
                        <li key={file.id}>
                            <a
                                href={file.url}
                                target="_blank"
                                rel="noreferrer"
                                className="da-card block overflow-hidden"
                            >
                                <img
                                    src={file.url}
                                    alt={t('customers.file_from_job', {
                                        number: file.job_number ?? '',
                                    })}
                                    loading="lazy"
                                    className="aspect-square w-full object-cover"
                                />
                                <span className="block truncate px-2 py-1 text-xs text-muted-foreground">
                                    {t('customers.file_from_job', {
                                        number: file.job_number ?? '',
                                    })}
                                    {file.taken_at &&
                                        ` · ${time.dateOnly(file.taken_at.slice(0, 10))}`}
                                </span>
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );

    return (
        <>
            <Head title={customer.display_name} />

            <ScreenHeader
                title={customer.display_name}
                subtitle={contactPerson || undefined}
                back={index()}
                actions={
                    <ActionSheet
                        title={customer.display_name}
                        items={[
                            canUpdate && {
                                key: 'edit',
                                label: t('customers.edit'),
                                icon: <Pencil />,
                                onSelect: () =>
                                    router.visit(edit(customer.id).url),
                            },
                            'separator' as const,
                            canDelete && {
                                key: 'delete',
                                label: t('customers.delete'),
                                icon: <Trash2 />,
                                destructive: true,
                                onSelect: () => setDeleteOpen(true),
                            },
                        ]}
                    />
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-4 p-4 sm:p-6">
                {/* Main block: always the same, above the tabs. */}
                <section
                    className="da-card space-y-3 p-4"
                    data-test="customer-main"
                >
                    <div className="flex items-center gap-3">
                        {canUpdate ? (
                            <CustomerAvatarPicker
                                customerId={customer.id}
                                icon={customer.avatar_icon}
                                style={customer.avatar_style}
                                name={customer.display_name}
                                size="lg"
                            />
                        ) : (
                            <CustomerAvatar
                                icon={customer.avatar_icon}
                                name={customer.display_name}
                                size="lg"
                            />
                        )}
                        <div className="min-w-0 flex-1">
                            <h2 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                                <span className="min-w-0 break-words">
                                    {customer.display_name}
                                </span>
                                {customer.type !== 'residential' && (
                                    <span
                                        className="da-badge rounded-full px-2.5 text-xs leading-6 font-medium"
                                        data-test="customer-type"
                                    >
                                        {customer.type_label}
                                    </span>
                                )}
                            </h2>
                            {contactPerson && (
                                <p className="text-sm text-muted-foreground">
                                    {contactPerson}
                                </p>
                            )}
                            <p className="text-sm" data-test="customer-balance">
                                <span className="text-muted-foreground">
                                    {t('customers.balance')}:{' '}
                                </span>
                                {owed.length > 0 ? (
                                    <button
                                        type="button"
                                        className="font-semibold text-danger"
                                        onClick={() => setTab('work')}
                                    >
                                        {t('customers.owes', {
                                            amount: owed
                                                .map((row) =>
                                                    money(
                                                        row.owed,
                                                        row.currency,
                                                    ),
                                                )
                                                .join(' + '),
                                        })}
                                    </button>
                                ) : (
                                    <span className="font-semibold">
                                        {t('customers.nothing_owed')}
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>

                    {primaryPhone && (
                        <div
                            className="grid grid-cols-2 gap-2"
                            data-test="customer-actions"
                        >
                            <Button asChild variant="success" className="px-2">
                                <a href={telUrl(primaryPhone)}>
                                    <Phone /> {t('customers.call')}
                                </a>
                            </Button>
                            {textButton}
                        </div>
                    )}
                </section>

                <SwipeTabs<Tab>
                    label={t('customers.tabs.label')}
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        {
                            value: 'client',
                            label: t('customers.tabs.client'),
                            content: clientTab,
                        },
                        {
                            value: 'work',
                            label: t('customers.tabs.work'),
                            count: summary.jobs,
                            content: workTab,
                        },
                        {
                            value: 'notes',
                            label: t('customers.tabs.notes'),
                            content: notesTab,
                        },
                        {
                            value: 'files',
                            label: t('customers.tabs.files'),
                            count: files.length,
                            content: filesTab,
                        },
                    ]}
                />
            </div>

            <ConfirmDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                destructive
                title={t('customers.delete')}
                description={t('customers.confirm_delete', {
                    name: customer.display_name,
                })}
                confirmLabel={t('customers.delete')}
                onConfirm={() => router.delete(destroy(customer.id).url)}
            />

            <PropertyDialog
                open={propertyDialog.open}
                onOpenChange={(open) =>
                    setPropertyDialog((s) => ({ ...s, open }))
                }
                customerId={customer.id}
                customerName={customer.display_name}
                property={propertyDialog.property}
                countries={countries}
            />

            <ApplianceDialog
                open={applianceFor !== null}
                onOpenChange={(open) => !open && setApplianceFor(null)}
                propertyId={applianceFor?.id}
                appliance={null}
                description={applianceFor?.full_address ?? ''}
                applianceTypes={applianceTypes}
                manufacturers={manufacturers}
            />
        </>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'customers.title', href: index() }],
};
