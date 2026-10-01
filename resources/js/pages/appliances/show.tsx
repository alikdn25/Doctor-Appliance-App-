import { Head, Link, router } from '@inertiajs/react';
import {
    History,
    MapPin,
    Pencil,
    ShieldAlert,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ApplianceDialog } from '@/components/customers/appliance-dialog';
import type { ApplianceData } from '@/components/customers/appliance-dialog';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTrans } from '@/lib/i18n';
import { destroy } from '@/routes/appliances';
import { index, show as showCustomer } from '@/routes/customers';
import type { Option } from '@/types';

type Props = {
    appliance: ApplianceData & { type_label: string; under_warranty: boolean };
    property: { id: number; label: string | null; full_address: string };
    customer: { id: number; display_name: string };
    canUpdate: boolean;
    applianceTypes: Option[];
    manufacturers: string[];
};

export default function ApplianceShow({
    appliance,
    property,
    customer,
    canUpdate,
    applianceTypes,
    manufacturers,
}: Props) {
    const t = useTrans();
    const [editing, setEditing] = useState(false);
    const title = [appliance.manufacturer, appliance.type_label]
        .filter(Boolean)
        .join(' ');

    const remove = () => {
        if (confirm(t('appliances.confirm_delete'))) {
            router.delete(destroy(appliance.id).url);
        }
    };

    const rows: [string, string | null][] = [
        ['model_number', appliance.model_number],
        ['serial_number', appliance.serial_number],
        ['purchase_date', appliance.purchase_date],
        ['install_date', appliance.install_date],
        ['warranty_expires_on', appliance.warranty_expires_on],
    ];

    return (
        <>
            <Head title={title} />

            <div className="max-w-3xl space-y-6 p-4">
                <PageHeader
                    title={title}
                    actions={
                        canUpdate && (
                            <Button
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                <Pencil /> {t('common.edit')}
                            </Button>
                        )
                    }
                />

                <Link
                    href={showCustomer(customer.id)}
                    className="flex items-start gap-2 rounded-lg border p-3 text-sm hover:bg-muted/50"
                >
                    <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <span>
                        <span className="font-medium">
                            {customer.display_name}
                        </span>
                        <br />
                        {property.label && `${property.label} · `}
                        {property.full_address}
                    </span>
                </Link>

                <div className="grid gap-6 sm:grid-cols-2">
                    <section className="space-y-3">
                        <h2 className="text-base font-medium">
                            {t('appliances.sections.details')}
                        </h2>
                        {appliance.warranty_expires_on && (
                            <Badge
                                variant={
                                    appliance.under_warranty
                                        ? 'default'
                                        : 'secondary'
                                }
                            >
                                {appliance.under_warranty ? (
                                    <ShieldCheck />
                                ) : (
                                    <ShieldAlert />
                                )}
                                {appliance.under_warranty
                                    ? t('appliances.under_warranty')
                                    : t('appliances.warranty_expired')}
                            </Badge>
                        )}
                        <dl className="divide-y rounded-lg border text-sm">
                            {rows.map(([field, value]) => (
                                <div
                                    key={field}
                                    className="flex justify-between gap-4 px-3 py-2"
                                >
                                    <dt className="text-muted-foreground">
                                        {t(`appliances.fields.${field}`)}
                                    </dt>
                                    <dd className="text-right font-medium break-all select-all">
                                        {value ?? '—'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {appliance.warranty_notes && (
                            <div className="text-sm">
                                <div className="text-muted-foreground">
                                    {t('appliances.fields.warranty_notes')}
                                </div>
                                <p className="whitespace-pre-line">
                                    {appliance.warranty_notes}
                                </p>
                            </div>
                        )}
                        {appliance.notes && (
                            <div className="text-sm">
                                <div className="text-muted-foreground">
                                    {t('appliances.fields.notes')}
                                </div>
                                <p className="whitespace-pre-line">
                                    {appliance.notes}
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="space-y-3">
                        <h2 className="text-base font-medium">
                            {t('appliances.rating_plate')}
                        </h2>
                        {appliance.rating_plate_url ? (
                            <a
                                href={appliance.rating_plate_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <img
                                    src={appliance.rating_plate_url}
                                    alt={t('appliances.rating_plate')}
                                    className="w-full rounded-lg border object-contain"
                                />
                            </a>
                        ) : (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                {t('appliances.no_rating_plate')}
                            </p>
                        )}
                    </section>
                </div>

                <section className="space-y-2 rounded-lg border border-dashed p-4">
                    <h2 className="flex items-center gap-2 text-base font-medium">
                        <History className="size-4" />
                        {t('appliances.repair_history')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('appliances.no_repairs')}
                    </p>
                </section>

                {canUpdate && (
                    <Button variant="destructive" onClick={remove}>
                        <Trash2 /> {t('appliances.delete')}
                    </Button>
                )}
            </div>

            <ApplianceDialog
                open={editing}
                onOpenChange={setEditing}
                appliance={appliance}
                description={property.full_address}
                applianceTypes={applianceTypes}
                manufacturers={manufacturers}
            />
        </>
    );
}

ApplianceShow.layout = {
    breadcrumbs: [{ title: 'customers.title', href: index() }],
};
