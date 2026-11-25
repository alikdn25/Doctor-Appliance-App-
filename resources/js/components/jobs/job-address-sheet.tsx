import { router, usePage } from '@inertiajs/react';
import { Check, MapPin, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    AddressEntry,
    emptyAddress,
} from '@/components/customers/address-entry';
import type { AddressData } from '@/components/customers/address-entry';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTrans } from '@/lib/i18n';
import { address as jobAddress } from '@/routes/jobs';
import { update as updateProperty } from '@/routes/properties';

/** The job's saved address, for "Edit this address". */
export type AddressEditable = {
    id: number;
} & { [K in keyof AddressData]?: string | null };

/**
 * The job's address: another address of the same customer in one tap, or a new one (saved on the job and on the
 * customer; a job without an address gets it in place of the empty one).
 */
export function JobAddressSheet({
    open,
    onOpenChange,
    jobId,
    current,
    editable = null,
    others,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    current: string;
    /** The job's address as saved: "Edit this address" corrects it (open jobs at it follow, closed ones keep it). */
    editable?: AddressEditable | null;
    others: { id: number; full_address: string }[];
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';
    const [address, setAddress] = useState<AddressData>(emptyAddress(country));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    // "pick" = the list; "edit" = correct this address; "new" = a new one.
    const [mode, setMode] = useState<'pick' | 'edit' | 'new'>('pick');
    const startEdit = () => {
        if (!editable) return;
        const base = emptyAddress(country);
        setAddress({
            ...base,
            ...Object.fromEntries(
                Object.keys(base).map((key) => [
                    key,
                    (editable as Record<string, unknown>)[key] ?? '',
                ]),
            ),
        } as AddressData);
        setMode('edit');
    };
    const close = (next: boolean) => {
        if (!next) setMode('pick');
        onOpenChange(next);
    };

    const saveEdit = (event: FormEvent) => {
        event.preventDefault();
        if (!editable) return;
        setBusy(true);
        router.put(updateProperty(editable.id).url, address as never, {
            preserveScroll: true,
            onSuccess: () => {
                setErrors({});
                close(false);
            },
            onError: (e) => setErrors(e),
            onFinish: () => setBusy(false),
        });
    };

    const send = (data: Record<string, unknown>) => {
        setBusy(true);
        router.put(jobAddress(jobId).url, data as never, {
            preserveScroll: true,
            onSuccess: () => {
                setAddress(emptyAddress(country));
                setErrors({});
                close(false);
            },
            onError: (e) => setErrors(e),
            onFinish: () => setBusy(false),
        });
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(address);
    };

    return (
        <Sheet open={open} onOpenChange={close}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[90dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="job-address-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>
                        {current
                            ? t('jobs.address.change')
                            : t('jobs.address.add')}
                    </SheetTitle>
                    <SheetDescription>
                        {current || t('jobs.quick.address_pending')}
                    </SheetDescription>
                </SheetHeader>
                {mode === 'pick' && others.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-semibold">
                            {t('jobs.address.other')}
                        </h3>
                        <ul className="space-y-1">
                            {others.map((other) => (
                                <li key={other.id}>
                                    <button
                                        type="button"
                                        disabled={busy}
                                        className="da-chip da-press flex min-h-12 w-full items-center gap-3 rounded-2xl px-4 text-left text-sm"
                                        onClick={() =>
                                            send({ property_id: other.id })
                                        }
                                    >
                                        <MapPin className="size-4 shrink-0" />
                                        <span className="min-w-0 flex-1">
                                            {other.full_address}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {mode === 'pick' && (
                    <div className="grid gap-2">
                        {editable && (
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-12 justify-start"
                                onClick={startEdit}
                                data-test="job-address-edit"
                            >
                                <Pencil /> {t('jobs.address.edit')}
                            </Button>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-12 justify-start"
                            onClick={() => {
                                setAddress(emptyAddress(country));
                                setMode('new');
                            }}
                            data-test="job-address-new"
                        >
                            <Plus /> {t('jobs.address.new_button')}
                        </Button>
                    </div>
                )}
                {mode !== 'pick' && (
                    <form
                        onSubmit={mode === 'edit' ? saveEdit : submit}
                        className="space-y-3"
                    >
                        <h3 className="text-sm font-semibold">
                            {mode === 'edit'
                                ? t('jobs.address.edit')
                                : t('jobs.address.new')}
                        </h3>
                        <AddressEntry
                            id="job-address"
                            value={address}
                            errors={errors}
                            onChange={(patch) =>
                                setAddress((a) => ({ ...a, ...patch }))
                            }
                        />
                        <Button type="submit" className="w-full" loading={busy}>
                            <Check /> {t('jobs.address.save')}
                        </Button>
                    </form>
                )}
            </SheetContent>
        </Sheet>
    );
}
