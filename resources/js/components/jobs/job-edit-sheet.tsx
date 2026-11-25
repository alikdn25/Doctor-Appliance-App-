import { router, usePage } from '@inertiajs/react';
import { Check, Sparkles, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    AddressEntry,
    addressComplete,
    emptyAddress,
} from '@/components/customers/address-entry';
import type { AddressData } from '@/components/customers/address-entry';
import { FormField } from '@/components/form-field';
import type { Assignable } from '@/components/jobs/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { MicButton } from '@/components/ui/mic-button';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { details, quickEdit } from '@/routes/jobs';
import { destroy as destroyVisit } from '@/routes/visits';

export type JobEditCustomer = {
    type: string;
    first_name: string | null;
    last_name: string | null;
    company_name: string | null;
};

type Form = {
    first_name: string;
    last_name: string;
    company_name: string;
    phone: string;
    email: string;
    description: string;
    notes: string;
    /** The customer's address the job is at, or "new". */
    address: string;
    date: string;
    start_time: string;
    end_time: string;
    technician_id: string;
    notify: boolean;
};

/**
 * The pencil on the job page's customer card (owner's update, October 24): customer name, address, date, arrival
 * window and technician, then Save. "Notify client" (off by default) sends the updated confirmation after Save;
 * unticked, nothing is sent.
 */
export function JobEditSheet({
    open,
    onOpenChange,
    jobId,
    customer,
    canRename,
    propertyId,
    currentAddress,
    otherAddresses,
    when,
    today,
    technicianId,
    technicians,
    canNotify,
    removableVisitId = null,
    contact,
    focusQuickEdit = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
    customer: JobEditCustomer;
    canRename: boolean;
    propertyId: number;
    /** "" when the job has no address yet. */
    currentAddress: string;
    otherAddresses: { id: number; full_address: string }[];
    /** The booked time now; null when nothing is booked. */
    when: { date: string; start_time: string; end_time: string } | null;
    today: string;
    technicianId: number | null;
    /** The people who can go on calls; null when the person may not change the technician (or works alone). */
    technicians: Assignable[] | null;
    /** A draft sends nothing until Finish booking: no box. */
    canNotify: boolean;
    /** The booked time that has not started yet: "Remove time" takes it off the schedule. */
    removableVisitId?: number | null;
    /** Phone, email, problem and notes, also changed by Quick edit. */
    contact: {
        phone: string;
        email: string;
        description: string;
        notes: string;
    };
    /** Opened by "Edit by voice": the cursor goes to Quick edit. */
    focusQuickEdit?: boolean;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const country = auth.company?.country ?? 'US';
    const business = customer.type !== 'residential';
    const initial = (): Form => ({
        first_name: customer.first_name ?? '',
        last_name: customer.last_name ?? '',
        company_name: customer.company_name ?? '',
        phone: contact.phone,
        email: contact.email,
        description: contact.description,
        notes: contact.notes,
        address: currentAddress ? String(propertyId) : 'new',
        date: when?.date ?? '',
        start_time: when?.start_time ?? '',
        end_time: when?.end_time ?? '',
        technician_id: technicianId === null ? '' : String(technicianId),
        notify: false,
    });
    const [form, setForm] = useState<Form>(initial);
    const [address, setAddress] = useState<AddressData>(emptyAddress(country));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const [quick, setQuick] = useState('');
    const [quickBusy, setQuickBusy] = useState(false);
    const [quickNote, setQuickNote] = useState<string | null>(null);
    // Fields Quick edit filled in: highlighted until the person saves.
    const [changed, setChanged] = useState<string[]>([]);
    const quickRef = useRef<HTMLTextAreaElement>(null);
    const set = (patch: Partial<Form>) => setForm((f) => ({ ...f, ...patch }));
    const mark = (field: string) =>
        changed.includes(field)
            ? 'bg-amber-50 ring-2 ring-amber-400'
            : undefined;

    useEffect(() => {
        if (open) {
            setForm(initial());
            setAddress(emptyAddress(country));
            setErrors({});
            setQuick('');
            setQuickNote(null);
            setChanged([]);
            if (focusQuickEdit) {
                window.setTimeout(() => quickRef.current?.focus(), 150);
            }
        }
        // Reset only when the sheet opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const newAddress = form.address === 'new';

    // Quick edit: the AI returns only the fields named in the text; they are filled in and highlighted. Nothing is
    // saved until Save.
    const applyQuick = async () => {
        if (quick.trim() === '' || quickBusy) return;
        setQuickBusy(true);
        setQuickNote(null);
        try {
            const response = await sendJson(quickEdit(jobId).url, 'POST', {
                text: quick,
                current: {
                    date: form.date,
                    start_time: form.start_time,
                    end_time: form.end_time,
                    technician:
                        technicians?.find(
                            (p) => String(p.id) === form.technician_id,
                        )?.name ?? '',
                    first_name: form.first_name,
                    last_name: form.last_name,
                    phone: form.phone,
                    email: form.email,
                    address: currentAddress,
                    problem: form.description,
                    notes: form.notes,
                },
            });
            const body = (await response.json()) as {
                changes?: Record<string, string | boolean>;
                message?: string;
            };
            const changes = body.changes ?? {};
            if (!response.ok || Object.keys(changes).length === 0) {
                setQuickNote(
                    body.message ?? t('automation.quick_edit.not_understood'),
                );

                return;
            }
            const patch: Partial<Form> = {};
            const touched: string[] = [];
            const put = (key: keyof Form, value: unknown) => {
                if (typeof value === 'string') {
                    (patch as Record<string, string>)[key] = value;
                    touched.push(key);
                }
            };
            put('date', changes.date);
            put('start_time', changes.start_time);
            put('end_time', changes.end_time);
            if (technicians !== null)
                put('technician_id', changes.technician_id);
            if (canRename) {
                put('first_name', changes.first_name);
                put('last_name', changes.last_name);
                put('phone', changes.phone);
                put('email', changes.email);
            }
            put('description', changes.problem);
            put('notes', changes.notes);
            if (typeof changes.address === 'string') {
                patch.address = 'new';
                setAddress((a) => ({ ...a, line1: changes.address as string }));
                touched.push('address');
            }
            set(patch);
            setChanged(touched);
            setQuickNote(t('automation.quick_edit.filled'));
        } catch {
            setQuickNote(t('automation.inbox.failed'));
        } finally {
            setQuickBusy(false);
        }
    };
    const removeTime = () => {
        if (
            removableVisitId === null ||
            !window.confirm(t('jobs.confirm_delete_visit'))
        )
            return;
        router.delete(destroyVisit(removableVisitId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const data: Record<string, unknown> = {
            notify: canNotify && form.notify,
        };
        if (canRename) {
            if (business) {
                data.company_name = form.company_name;
            } else {
                data.first_name = form.first_name;
                data.last_name = form.last_name;
            }
            if (form.phone !== contact.phone) data.phone = form.phone;
            if (form.email !== contact.email) data.email = form.email;
        }
        if (form.description !== contact.description) {
            data.description = form.description;
        }
        if (form.notes !== contact.notes) data.notes = form.notes;
        if (newAddress) {
            if (addressComplete(address)) {
                Object.assign(data, address, { address: 'new' });
            }
        } else {
            data.property_id = Number(form.address);
        }
        if (form.date || form.start_time || form.end_time) {
            Object.assign(data, {
                date: form.date,
                start_time: form.start_time,
                end_time: form.end_time,
            });
        }
        if (technicians !== null) {
            data.technician_id =
                form.technician_id === '' ? null : Number(form.technician_id);
        }

        setBusy(true);
        router.put(details(jobId).url, data as never, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (e) => setErrors(e),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[90dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="job-edit-sheet"
                // No keyboard on open: the person taps the field they want to change.
                onOpenAutoFocus={(event) => event.preventDefault()}
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{t('jobs.details.edit')}</SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('jobs.details.edit')}
                    </SheetDescription>
                </SheetHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div
                        className="space-y-2 rounded-2xl border border-violet-200 bg-violet-50/60 p-3"
                        data-test="quick-edit"
                    >
                        <label
                            htmlFor="quick-edit"
                            className="text-sm font-semibold"
                        >
                            {t('automation.quick_edit.label')}
                        </label>
                        <div className="flex items-end gap-2">
                            <Textarea
                                voice={false}
                                ref={quickRef}
                                id="quick-edit"
                                value={quick}
                                rows={2}
                                maxLength={2000}
                                placeholder={t(
                                    'automation.quick_edit.placeholder',
                                )}
                                onChange={(e) => setQuick(e.target.value)}
                                className="min-w-0 flex-1 bg-white"
                            />
                            <MicButton value={quick} onChange={setQuick} />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            loading={quickBusy}
                            disabled={quick.trim() === ''}
                            onClick={() => void applyQuick()}
                            data-test="quick-edit-apply"
                        >
                            <Sparkles /> {t('automation.quick_edit.apply')}
                        </Button>
                        {quickNote && (
                            <p
                                role="status"
                                className="text-sm text-violet-800"
                            >
                                {quickNote}
                            </p>
                        )}
                    </div>
                    {canRename &&
                        (business ? (
                            <FormField
                                id="edit-company-name"
                                label={t('jobs.details.name')}
                                error={errors.company_name ?? errors.first_name}
                            >
                                <Input
                                    id="edit-company-name"
                                    value={form.company_name}
                                    maxLength={255}
                                    onChange={(e) =>
                                        set({ company_name: e.target.value })
                                    }
                                />
                            </FormField>
                        ) : (
                            <div className="grid grid-cols-2 gap-3">
                                <FormField
                                    id="edit-first-name"
                                    label={t('customers.fields.first_name')}
                                    error={errors.first_name}
                                >
                                    <Input
                                        id="edit-first-name"
                                        className={mark('first_name')}
                                        value={form.first_name}
                                        maxLength={100}
                                        autoComplete="off"
                                        onChange={(e) =>
                                            set({ first_name: e.target.value })
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="edit-last-name"
                                    label={t('customers.fields.last_name')}
                                    error={errors.last_name}
                                >
                                    <Input
                                        id="edit-last-name"
                                        className={mark('last_name')}
                                        value={form.last_name}
                                        maxLength={100}
                                        autoComplete="off"
                                        onChange={(e) =>
                                            set({ last_name: e.target.value })
                                        }
                                    />
                                </FormField>
                            </div>
                        ))}

                    {canRename && (
                        <div className="grid grid-cols-2 gap-3">
                            <FormField
                                id="edit-phone"
                                label={t('customers.fields.phone')}
                                error={errors.phone}
                            >
                                <Input
                                    id="edit-phone"
                                    type="tel"
                                    inputMode="tel"
                                    className={mark('phone')}
                                    value={form.phone}
                                    maxLength={32}
                                    onChange={(e) =>
                                        set({ phone: e.target.value })
                                    }
                                />
                            </FormField>
                            <FormField
                                id="edit-email"
                                label={t('customers.fields.email')}
                                error={errors.email}
                            >
                                <Input
                                    id="edit-email"
                                    type="email"
                                    className={mark('email')}
                                    value={form.email}
                                    maxLength={255}
                                    onChange={(e) =>
                                        set({ email: e.target.value })
                                    }
                                />
                            </FormField>
                        </div>
                    )}

                    <FormField
                        id="edit-address"
                        label={t('jobs.details.address')}
                        error={errors.property_id}
                    >
                        <NativeSelect
                            id="edit-address"
                            className={mark('address')}
                            value={form.address}
                            onChange={(e) => set({ address: e.target.value })}
                        >
                            {currentAddress && (
                                <option value={propertyId}>
                                    {currentAddress}
                                </option>
                            )}
                            {otherAddresses.map((other) => (
                                <option key={other.id} value={other.id}>
                                    {other.full_address}
                                </option>
                            ))}
                            <option value="new">
                                {t('jobs.details.address_new')}
                            </option>
                        </NativeSelect>
                    </FormField>
                    {newAddress && (
                        <AddressEntry
                            id="edit-new-address"
                            value={address}
                            errors={errors}
                            onChange={(patch) =>
                                setAddress((a) => ({ ...a, ...patch }))
                            }
                        />
                    )}

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            {t('jobs.details.when')}
                        </legend>
                        <div className="grid grid-cols-[1.4fr_1fr_1fr] gap-2">
                            <FormField
                                id="edit-date"
                                label={t('jobs.visit_fields.date')}
                                error={errors.date}
                            >
                                <Input
                                    id="edit-date"
                                    className={mark('date')}
                                    type="date"
                                    min={when ? undefined : today}
                                    value={form.date}
                                    onChange={(e) =>
                                        set({ date: e.target.value })
                                    }
                                />
                            </FormField>
                            <FormField
                                id="edit-start"
                                label={t('jobs.visit_fields.start_time')}
                                error={errors.start_time}
                            >
                                <Input
                                    id="edit-start"
                                    className={mark('start_time')}
                                    type="time"
                                    step={900}
                                    value={form.start_time}
                                    onChange={(e) =>
                                        set({ start_time: e.target.value })
                                    }
                                />
                            </FormField>
                            <FormField
                                id="edit-end"
                                label={t('jobs.visit_fields.end_time')}
                                error={errors.end_time}
                            >
                                <Input
                                    id="edit-end"
                                    className={mark('end_time')}
                                    type="time"
                                    step={900}
                                    value={form.end_time}
                                    onChange={(e) =>
                                        set({ end_time: e.target.value })
                                    }
                                />
                            </FormField>
                        </div>
                    </fieldset>

                    {technicians !== null && (
                        <FormField
                            id="edit-technician"
                            label={t('jobs.details.technician')}
                            error={errors.technician_id}
                        >
                            <NativeSelect
                                id="edit-technician"
                                className={mark('technician_id')}
                                value={form.technician_id}
                                onChange={(e) =>
                                    set({ technician_id: e.target.value })
                                }
                            >
                                <option value="">
                                    {t('jobs.technician.not_assigned')}
                                </option>
                                {technicians.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    )}

                    <FormField
                        id="edit-problem"
                        label={t('jobs.fields.description')}
                        error={errors.description}
                    >
                        <Textarea
                            id="edit-problem"
                            rows={2}
                            className={mark('description')}
                            value={form.description}
                            maxLength={5000}
                            onChange={(e) =>
                                set({ description: e.target.value })
                            }
                        />
                    </FormField>
                    <FormField
                        id="edit-notes"
                        label={t('jobs.fields.notes')}
                        error={errors.notes}
                    >
                        <Textarea
                            id="edit-notes"
                            rows={2}
                            className={mark('notes')}
                            value={form.notes}
                            maxLength={5000}
                            onChange={(e) => set({ notes: e.target.value })}
                        />
                    </FormField>

                    {canNotify && (
                        <NotifyClientBox
                            id="edit-notify"
                            checked={form.notify}
                            onChange={(notify) => set({ notify })}
                        />
                    )}

                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        loading={busy}
                        data-test="job-edit-save"
                    >
                        <Check /> {t('common.save')}
                    </Button>
                    {removableVisitId !== null && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-full text-danger"
                            onClick={removeTime}
                            data-test="job-edit-remove-time"
                        >
                            <Trash2 /> {t('jobs.delete_visit')}
                        </Button>
                    )}
                </form>
            </SheetContent>
        </Sheet>
    );
}

/** "Notify client": off by default; ticked, the updated confirmation goes after Save. */
export function NotifyClientBox({
    id,
    checked,
    onChange,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    const t = useTrans();

    return (
        <label
            htmlFor={id}
            className="da-card flex min-h-12 cursor-pointer items-center gap-3 p-3"
            data-test="notify-client"
        >
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <span className="min-w-0 flex-1">
                <span className="block text-sm font-semibold">
                    {t('jobs.confirmation.notify')}
                </span>
                <span className="block text-xs text-muted-foreground">
                    {t('jobs.confirmation.notify_hint')}
                </span>
            </span>
        </label>
    );
}
