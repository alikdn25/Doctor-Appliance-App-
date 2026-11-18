import { router } from '@inertiajs/react';
import { PackageCheck, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { part as partRoute, partArrived } from '@/routes/jobs';
import { send as sendArrived } from '@/routes/jobs/part-arrived';

export type JobPart = {
    name: string | null;
    number: string | null;
    supplier: string | null;
    ordered_on: string | null;
    suggestion: { part_number: string; description: string } | null;
};

/**
 * The part a job waits for (owner's task of November 18): name, number, supplier and order date, with the AI's
 * possible part number marked "check" (Use fills the field; Save stores it). In Waiting for parts the office has
 * "Part arrived": a text with the three nearest free windows, sent only after Send.
 */
export function PartCard({
    jobId,
    part,
    canEdit,
    canTextArrived,
}: {
    jobId: number;
    part: JobPart;
    canEdit: boolean;
    canTextArrived: boolean;
}) {
    const t = useTrans();
    const initial = {
        part_name: part.name ?? '',
        part_number: part.number ?? '',
        part_supplier: part.supplier ?? '',
        part_ordered_on: part.ordered_on ?? '',
    };
    const [form, setForm] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [arrivedOpen, setArrivedOpen] = useState(false);
    const dirty = JSON.stringify(form) !== JSON.stringify(initial);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.put(partRoute(jobId).url, form, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    };

    const field = (key: keyof typeof form, label: string, type = 'text') => (
        <FormField id={`part-${key}`} label={label}>
            <Input
                id={`part-${key}`}
                type={type}
                value={form[key]}
                disabled={!canEdit}
                maxLength={255}
                onChange={(e) => setForm({ ...form, [key]: e.target.value })}
            />
        </FormField>
    );

    return (
        <section className="da-card space-y-3 p-4" data-test="part-card">
            <h2 className="font-semibold">{t('automation.parts.title')}</h2>
            <form onSubmit={submit} className="space-y-3">
                {field('part_name', t('automation.parts.name'))}
                {field('part_number', t('automation.parts.number'))}
                {part.suggestion && !form.part_number && (
                    <div
                        className="flex items-center gap-2 rounded-xl bg-amber-50 p-2 text-sm text-amber-950"
                        data-test="part-suggestion"
                    >
                        <span className="min-w-0 flex-1">
                            {t('automation.parts.suggestion', {
                                number: part.suggestion.part_number,
                            })}
                            {part.suggestion.description &&
                                ` — ${part.suggestion.description}`}{' '}
                            <span className="rounded bg-amber-200 px-1 text-xs font-bold uppercase">
                                {t('automation.parts.check')}
                            </span>
                        </span>
                        {canEdit && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setForm({
                                        ...form,
                                        part_number:
                                            part.suggestion!.part_number,
                                    })
                                }
                            >
                                {t('automation.parts.use')}
                            </Button>
                        )}
                    </div>
                )}
                <div className="grid grid-cols-2 gap-3">
                    {field('part_supplier', t('automation.parts.supplier'))}
                    {field(
                        'part_ordered_on',
                        t('automation.parts.ordered_on'),
                        'date',
                    )}
                </div>
                {canEdit && dirty && (
                    <Button type="submit" className="w-full" loading={busy}>
                        {t('common.save')}
                    </Button>
                )}
            </form>
            {canTextArrived && (
                <>
                    <Button
                        variant="outline"
                        size="lg"
                        className="w-full"
                        onClick={() => setArrivedOpen(true)}
                        data-test="part-arrived"
                    >
                        <PackageCheck /> {t('automation.parts.arrived')}
                    </Button>
                    <PartArrivedSheet
                        open={arrivedOpen}
                        onOpenChange={setArrivedOpen}
                        jobId={jobId}
                    />
                </>
            )}
        </section>
    );
}

function PartArrivedSheet({
    open,
    onOpenChange,
    jobId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    jobId: number;
}) {
    const t = useTrans();
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setError(null);
        setText('');
        fetch(partArrived(jobId).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((body: { text?: string }) => setText(body.text ?? ''))
            .catch(() => setError(t('automation.inbox.failed')));
    }, [open, jobId, t]);

    const send = () =>
        router.post(
            sendArrived(jobId).url,
            { text },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    setError(String(Object.values(errors)[0] ?? '')),
            },
        );

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[90dvh] max-w-xl flex-col gap-3 overflow-y-auto rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
                data-test="part-arrived-sheet"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>
                        {t('automation.parts.arrived_title')}
                    </SheetTitle>
                    <SheetDescription>
                        {t('automation.parts.arrived_hint')}
                    </SheetDescription>
                </SheetHeader>
                <Textarea
                    value={text}
                    rows={7}
                    maxLength={1600}
                    onChange={(e) => setText(e.target.value)}
                    aria-label={t('automation.parts.arrived_title')}
                />
                {error && (
                    <p role="alert" className="text-sm text-danger">
                        {error}
                    </p>
                )}
                <Button
                    size="lg"
                    className="w-full"
                    loading={busy}
                    disabled={text.trim() === ''}
                    onClick={send}
                    data-test="part-arrived-send"
                >
                    <Send /> {t('automation.parts.send')}
                </Button>
            </SheetContent>
        </Sheet>
    );
}
