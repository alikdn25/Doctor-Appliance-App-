import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronRight,
    KeyRound,
    Mail,
    MapPin,
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
import type { DocumentRow } from '@/components/billing/types';
import { ApplianceDialog } from '@/components/customers/appliance-dialog';
import { PropertyDialog } from '@/components/customers/property-dialog';
import { JobList } from '@/components/jobs/job-list';
import type { JobRow } from '@/components/jobs/types';
import { mapsUrl, telUrl } from '@/components/customers/types';
import type { PropertyData } from '@/components/customers/types';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { show as showAppliance } from '@/routes/appliances';
import { destroy, edit, index } from '@/routes/customers';
import { create as createJob } from '@/routes/jobs';
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
    first_name: string | null;
    last_name: string | null;
    company_name: string | null;
    lead_source_label: string | null;
    tags: string[];
    notes: string | null;
    created_at: string | null;
    phones: {
        id: number;
        label_text: string;
        number: string;
        is_primary: boolean;
    }[];
    emails: {
        id: number;
        label_text: string;
        email: string;
        is_primary: boolean;
    }[];
    properties: PropertyRow[];
};

type Props = {
    customer: Customer;
    jobs: JobRow[];
    estimates: DocumentRow[];
    invoices: DocumentRow[];
    canCreateJob: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    applianceTypes: Option[];
    manufacturers: string[];
};

export default function CustomerShow({
    customer,
    jobs,
    estimates,
    invoices,
    canCreateJob,
    canUpdate,
    canDelete,
    applianceTypes,
    manufacturers,
}: Props) {
    const t = useTrans();
    const [propertyDialog, setPropertyDialog] = useState<{
        open: boolean;
        property: PropertyData | null;
    }>({ open: false, property: null });
    const [applianceFor, setApplianceFor] = useState<PropertyRow | null>(null);

    const primaryPhone = customer.phones[0]?.number;
    const primaryEmail = customer.emails[0]?.email;
    const contactPerson =
        customer.company_name &&
        [customer.first_name, customer.last_name].filter(Boolean).join(' ');

    const remove = () => {
        if (
            confirm(
                t('customers.confirm_delete', { name: customer.display_name }),
            )
        ) {
            router.delete(destroy(customer.id).url);
        }
    };

    const removeProperty = (property: PropertyRow) => {
        if (confirm(t('properties.confirm_delete'))) {
            router.delete(destroyProperty(property.id).url, {
                preserveScroll: true,
            });
        }
    };

    const placeholders = ['messages'] as const;

    return (
        <>
            <Head title={customer.display_name} />

            <div className="max-w-3xl space-y-6 p-4">
                <PageHeader
                    title={customer.display_name}
                    description={[
                        customer.type_label,
                        contactPerson,
                        customer.lead_source_label,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        canUpdate && (
                            <Button variant="outline" asChild>
                                <Link href={edit(customer.id)}>
                                    <Pencil /> {t('common.edit')}
                                </Link>
                            </Button>
                        )
                    }
                />

                {(primaryPhone || primaryEmail) && (
                    <div className="grid grid-cols-2 gap-2 sm:flex">
                        {primaryPhone && (
                            <Button asChild size="lg">
                                <a href={telUrl(primaryPhone)}>
                                    <Phone /> {t('customers.call')}
                                </a>
                            </Button>
                        )}
                        {primaryEmail && (
                            <Button asChild size="lg" variant="outline">
                                <a href={`mailto:${primaryEmail}`}>
                                    <Mail /> {t('customers.send_email')}
                                </a>
                            </Button>
                        )}
                    </div>
                )}

                <section className="space-y-2 rounded-lg border p-4">
                    <h2 className="text-sm font-medium">
                        {t('customers.sections.contacts')}
                    </h2>
                    <ul className="space-y-1 text-sm">
                        {customer.phones.map((p) => (
                            <li key={p.id} className="flex items-center gap-2">
                                <Phone className="size-4 text-muted-foreground" />
                                <a
                                    href={telUrl(p.number)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {p.number}
                                </a>
                                <span className="text-xs text-muted-foreground">
                                    {p.label_text}
                                </span>
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
                    {customer.tags.length > 0 && (
                        <div className="flex flex-wrap gap-1 pt-1">
                            {customer.tags.map((tag) => (
                                <Badge key={tag} variant="secondary">
                                    {tag}
                                </Badge>
                            ))}
                        </div>
                    )}
                    {customer.notes && (
                        <p className="pt-1 text-sm whitespace-pre-line text-muted-foreground">
                            {customer.notes}
                        </p>
                    )}
                    {customer.created_at && (
                        <p className="pt-1 text-xs text-muted-foreground">
                            {t('customers.customer_since', {
                                date: customer.created_at,
                            })}
                        </p>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-medium">
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
                        <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                            {t('properties.empty')}
                        </p>
                    )}

                    {customer.properties.map((property) => (
                        <article
                            key={property.id}
                            className="space-y-3 rounded-lg border p-4"
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
                                        {property.is_primary &&
                                            customer.properties.length > 1 && (
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
                                            className="size-9"
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
                                            className="size-9"
                                            aria-label={t('properties.delete')}
                                            onClick={() =>
                                                removeProperty(property)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                )}
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" asChild>
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
                                    <Button variant="outline" size="sm" asChild>
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
                                                property.site_contact_phone}
                                        </a>
                                    </Button>
                                )}
                            </div>

                            {(property.gate_code ||
                                property.access_notes ||
                                property.site_contact_name) && (
                                <dl className="space-y-1 text-sm text-muted-foreground">
                                    {property.gate_code && (
                                        <div className="flex items-center gap-2">
                                            <KeyRound className="size-4" />
                                            {t('properties.gate_code', {
                                                code: property.gate_code,
                                            })}
                                        </div>
                                    )}
                                    {property.site_contact_name && (
                                        <div className="flex items-center gap-2">
                                            <User className="size-4" />
                                            {t('properties.site_contact')}:{' '}
                                            {property.site_contact_name}
                                            {property.site_contact_phone &&
                                                ` · ${property.site_contact_phone}`}
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
                                    <ul className="divide-y rounded-md border">
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

                <section className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-medium">
                            {t('customers.sections.jobs')}
                        </h2>
                        {canCreateJob && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={createJob({
                                        query: { customer_id: customer.id },
                                    })}
                                >
                                    <Plus /> {t('jobs.add')}
                                </Link>
                            </Button>
                        )}
                    </div>
                    <JobList
                        jobs={jobs}
                        empty={t('customers.no_jobs')}
                        showCustomer={false}
                    />
                </section>

                <section className="space-y-2">
                    <h2 className="text-base font-medium">
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

                <section className="grid gap-2 sm:grid-cols-2">
                    {placeholders.map((section) => (
                        <div
                            key={section}
                            className="rounded-lg border border-dashed p-4"
                        >
                            <h2 className="text-sm font-medium">
                                {t(`customers.sections.${section}`)}
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                {t('customers.coming_soon')}
                            </p>
                        </div>
                    ))}
                </section>

                {canDelete && (
                    <Button variant="destructive" onClick={remove}>
                        <Trash2 /> {t('customers.delete')}
                    </Button>
                )}
            </div>

            <PropertyDialog
                open={propertyDialog.open}
                onOpenChange={(open) =>
                    setPropertyDialog((s) => ({ ...s, open }))
                }
                customerId={customer.id}
                customerName={customer.display_name}
                property={propertyDialog.property}
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
