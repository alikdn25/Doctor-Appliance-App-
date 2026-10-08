import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTrans } from '@/lib/i18n';
import { destroy, index, store, update } from '@/routes/taxes';

type TaxRate = {
    id: number;
    name: string;
    rate: string;
    is_compound: boolean;
    is_recoverable: boolean;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
    /** Line types charged by default; null = all. */
    applies_to: LineKind[] | null;
};

type LineKind = 'service' | 'part' | 'material';
const KINDS: LineKind[] = ['service', 'part', 'material'];

type TaxForm = {
    name: string;
    rate: string;
    is_compound: boolean;
    is_recoverable: boolean;
    is_default: boolean;
    is_active: boolean;
    sort_order: number;
    applies_to: LineKind[];
};

const formatRate = (rate: string) => `${Number(rate)}%`;

export default function TaxesIndex({
    taxRates,
    canManage,
}: {
    taxRates: TaxRate[];
    canManage: boolean;
}) {
    const t = useTrans();
    const [editing, setEditing] = useState<TaxRate | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<TaxForm>({
        name: '',
        rate: '',
        is_compound: false,
        is_default: false,
        is_active: true,
        is_recoverable: true,
        sort_order: 0,
        applies_to: KINDS,
    });

    const openForm = (taxRate: TaxRate | null) => {
        setEditing(taxRate);
        form.clearErrors();
        form.setData(
            taxRate
                ? {
                      name: taxRate.name,
                      rate: String(Number(taxRate.rate)),
                      is_compound: taxRate.is_compound,
                      is_recoverable: taxRate.is_recoverable,
                      is_default: taxRate.is_default,
                      is_active: taxRate.is_active,
                      sort_order: taxRate.sort_order,
                      applies_to: taxRate.applies_to ?? KINDS,
                  }
                : {
                      name: '',
                      rate: '',
                      is_compound: false,
                      is_recoverable: true,
                      is_default: taxRates.length === 0,
                      is_active: true,
                      sort_order: taxRates.length,
                      applies_to: KINDS,
                  },
        );
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            form.put(update(editing.id).url, options);
        } else {
            form.post(store().url, options);
        }
    };

    const remove = (taxRate: TaxRate) => {
        if (confirm(t('taxes.confirm_delete', { name: taxRate.name }))) {
            router.delete(destroy(taxRate.id).url, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={t('taxes.title')} />

            <div className="max-w-2xl p-4">
                <PageHeader
                    title={t('taxes.title')}
                    description={t('taxes.description')}
                    actions={
                        canManage && (
                            <Button onClick={() => openForm(null)}>
                                <Plus /> {t('taxes.add')}
                            </Button>
                        )
                    }
                />

                {taxRates.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {t('taxes.empty')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {taxRates.map((taxRate) => (
                            <li
                                key={taxRate.id}
                                className="flex items-center gap-3 px-4 py-3"
                            >
                                <div className="flex flex-1 flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {taxRate.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatRate(taxRate.rate)}
                                    </span>
                                    {taxRate.is_compound && (
                                        <Badge variant="outline">
                                            {t('taxes.compound')}
                                        </Badge>
                                    )}
                                    {taxRate.is_default && (
                                        <Badge>{t('taxes.default')}</Badge>
                                    )}
                                    {taxRate.applies_to !== null && (
                                        <span className="text-xs text-muted-foreground">
                                            {t('taxes.applies_to_list', {
                                                kinds: taxRate.applies_to
                                                    .map((kind) =>
                                                        t(
                                                            `billing.kinds.${kind}`,
                                                        ),
                                                    )
                                                    .join(', '),
                                            })}
                                        </span>
                                    )}
                                    {!taxRate.is_active && (
                                        <Badge variant="secondary">
                                            {t('common.inactive')}
                                        </Badge>
                                    )}
                                </div>
                                {canManage && (
                                    <div className="flex gap-1">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t('common.edit')}
                                            onClick={() => openForm(taxRate)}
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t('common.delete')}
                                            onClick={() => remove(taxRate)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? editing.name : t('taxes.add')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('taxes.form_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="grid gap-4">
                        <FormField
                            id="name"
                            label={t('taxes.fields.name')}
                            error={form.errors.name}
                        >
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder={t('taxes.name_placeholder')}
                                required
                            />
                        </FormField>
                        <FormField
                            id="rate"
                            label={t('taxes.fields.rate')}
                            error={form.errors.rate}
                        >
                            <Input
                                id="rate"
                                type="number"
                                inputMode="decimal"
                                step="0.0001"
                                min={0}
                                max={100}
                                value={form.data.rate}
                                onChange={(e) =>
                                    form.setData('rate', e.target.value)
                                }
                                required
                            />
                        </FormField>
                        <fieldset className="grid gap-1">
                            <legend className="text-sm font-medium">
                                {t('taxes.fields.applies_to')}
                            </legend>
                            <p className="text-xs text-muted-foreground">
                                {t('taxes.applies_to_hint')}
                            </p>
                            <div className="flex flex-wrap gap-x-4">
                                {KINDS.map((kind) => (
                                    <label
                                        key={kind}
                                        className="flex min-h-11 items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={form.data.applies_to.includes(
                                                kind,
                                            )}
                                            onCheckedChange={(c) =>
                                                form.setData(
                                                    'applies_to',
                                                    KINDS.filter((k) =>
                                                        k === kind
                                                            ? c === true
                                                            : form.data.applies_to.includes(
                                                                  k,
                                                              ),
                                                    ),
                                                )
                                            }
                                        />
                                        {t(`billing.kinds.${kind}`)}
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.applies_to} />
                        </fieldset>
                        <label className="flex min-h-9 items-start gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_compound}
                                onCheckedChange={(c) =>
                                    form.setData('is_compound', c === true)
                                }
                            />
                            <span>
                                {t('taxes.fields.is_compound')}
                                <span className="block text-xs text-muted-foreground">
                                    {t('taxes.compound_hint')}
                                </span>
                            </span>
                        </label>
                        <label className="flex min-h-9 items-start gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_recoverable}
                                onCheckedChange={(c) =>
                                    form.setData('is_recoverable', c === true)
                                }
                            />
                            <span>
                                {t('taxes.fields.is_recoverable')}
                                <span className="block text-xs text-muted-foreground">
                                    {t('taxes.recoverable_hint')}
                                </span>
                            </span>
                        </label>
                        <label className="flex min-h-9 items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_default}
                                onCheckedChange={(c) =>
                                    form.setData('is_default', c === true)
                                }
                            />
                            {t('taxes.fields.is_default')}
                        </label>
                        <label className="flex min-h-9 items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_active}
                                onCheckedChange={(c) =>
                                    form.setData('is_active', c === true)
                                }
                            />
                            {t('taxes.fields.is_active')}
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            {t('common.save')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

TaxesIndex.layout = {
    breadcrumbs: [{ title: 'taxes.title', href: index() }],
};
