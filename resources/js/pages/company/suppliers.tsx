import { Head, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { destroy, index, store, update } from '@/routes/company/suppliers';

type Supplier = {
    id: number;
    name: string;
    address: string | null;
    open_parts: number;
};

/**
 * Company → Suppliers (owner's task of November 19): where parts are bought, with the address. The Parts page groups
 * by supplier; the automatic "Buy at {supplier}" task gets the address (for "On the way").
 */
export default function Suppliers({ suppliers }: { suppliers: Supplier[] }) {
    const t = useTrans();

    return (
        <>
            <Head title={t('parts.suppliers.title')} />
            <ScreenHeader title={t('parts.suppliers.title')} />
            <div className="mx-auto w-full max-w-2xl space-y-3 p-4 pb-24 sm:p-6">
                <p className="text-sm text-muted-foreground">
                    {t('parts.suppliers.description')}
                </p>
                {suppliers.length === 0 && (
                    <p className="da-card p-6 text-center text-sm text-muted-foreground">
                        {t('parts.suppliers.empty')}
                    </p>
                )}
                {suppliers.map((supplier) => (
                    <SupplierForm key={supplier.id} supplier={supplier} />
                ))}
                <SupplierForm supplier={null} />
            </div>
        </>
    );
}

function SupplierForm({ supplier }: { supplier: Supplier | null }) {
    const t = useTrans();
    const [open, setOpen] = useState(supplier !== null);
    const form = useForm({
        name: supplier?.name ?? '',
        address: supplier?.address ?? '',
    });
    const dirty =
        form.data.name !== (supplier?.name ?? '') ||
        form.data.address !== (supplier?.address ?? '');

    if (!open) {
        return (
            <Button
                variant="outline"
                className="w-full"
                onClick={() => setOpen(true)}
                data-test="supplier-add"
            >
                <Plus /> {t('parts.suppliers.add')}
            </Button>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (supplier) {
            form.put(update(supplier.id).url, { preserveScroll: true });
        } else {
            form.post(store().url, {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    setOpen(false);
                },
            });
        }
    };

    const remove = () => {
        if (!supplier || !confirm(t('parts.suppliers.confirm_delete'))) return;
        router.delete(destroy(supplier.id).url, { preserveScroll: true });
    };

    const key = supplier?.id ?? 'new';

    return (
        <form
            onSubmit={submit}
            className="da-card space-y-3 p-4"
            data-test="supplier-form"
        >
            <FormField
                id={`supplier-name-${key}`}
                label={t('parts.suppliers.name')}
                error={form.errors.name}
            >
                <Input
                    id={`supplier-name-${key}`}
                    value={form.data.name}
                    maxLength={160}
                    autoFocus={supplier === null}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </FormField>
            <FormField
                id={`supplier-address-${key}`}
                label={t('parts.suppliers.address')}
                error={form.errors.address}
            >
                <Input
                    id={`supplier-address-${key}`}
                    value={form.data.address}
                    maxLength={500}
                    onChange={(e) => form.setData('address', e.target.value)}
                />
            </FormField>
            {supplier && (
                <p className="text-xs text-muted-foreground">
                    {t('parts.suppliers.open_parts', {
                        count: supplier.open_parts,
                    })}
                </p>
            )}
            <div className="flex gap-2">
                {(dirty || supplier === null) && (
                    <Button
                        type="submit"
                        className="flex-1"
                        disabled={form.data.name.trim() === ''}
                        loading={form.processing}
                    >
                        {t('parts.suppliers.save')}
                    </Button>
                )}
                {supplier && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-destructive"
                        onClick={remove}
                    >
                        <Trash2 /> {t('parts.suppliers.delete')}
                    </Button>
                )}
            </div>
        </form>
    );
}

Suppliers.layout = {
    breadcrumbs: [{ title: 'parts.suppliers.title', href: index() }],
};
