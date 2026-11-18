import { router, usePage } from '@inertiajs/react';
import { Send, UserRoundCheck } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { xsrfToken } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import * as handoff from '@/routes/jobs/handoff';

type Option = {
    key: string;
    name: string;
    phone: string | null;
    outside: boolean;
};

/**
 * "Hand to technician" (owner's task of November 19): pick a technician of the app or an outside one, see the two
 * texts (to the technician and to the customer), Confirm sends both. Nothing is sent before Confirm.
 */
export function HandoffSheet({ jobId }: { jobId: number }) {
    const t = useTrans();
    const { handoffOptions = [], job } = usePage<{
        handoffOptions?: Option[];
        job: { handed_to?: string | null };
    }>().props;
    const [open, setOpen] = useState(false);
    const [to, setTo] = useState<string | null>(null);
    const [preview, setPreview] = useState<{
        technician: string;
        customer: string | null;
    } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    if (handoffOptions.length === 0 && !job.handed_to) return null;

    const pick = async (key: string) => {
        setTo(key);
        setPreview(null);
        setError(null);
        setBusy(true);
        try {
            const response = await fetch(handoff.preview(jobId).url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ to: key }),
            });
            const data = (await response.json()) as {
                technician?: string;
                customer?: string | null;
                message?: string;
            };
            if (!response.ok || !data.technician) {
                throw new Error(data.message ?? '');
            }
            setPreview({
                technician: data.technician,
                customer: data.customer ?? null,
            });
        } catch (e) {
            setError(e instanceof Error && e.message ? e.message : '…');
        } finally {
            setBusy(false);
        }
    };

    const close = () => {
        setOpen(false);
        setTo(null);
        setPreview(null);
        setError(null);
    };

    return (
        <>
            {job.handed_to && (
                <p
                    className="rounded-xl bg-sky-50 px-3 py-2 text-sm font-medium text-sky-900"
                    data-test="handed-to"
                >
                    {t('handoff.handed', { name: job.handed_to })}
                </p>
            )}
            {handoffOptions.length > 0 && (
                <Button
                    variant="outline"
                    size="lg"
                    className="w-full"
                    onClick={() => setOpen(true)}
                    data-test="handoff-open"
                >
                    <UserRoundCheck /> {t('handoff.button')}
                </Button>
            )}
            {open && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label={t('handoff.title')}
                    className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center"
                >
                    <div className="max-h-[90vh] w-full max-w-lg space-y-3 overflow-y-auto rounded-t-2xl bg-background p-4 sm:rounded-2xl">
                        <h2 className="text-lg font-semibold">
                            {t('handoff.pick')}
                        </h2>
                        <div className="flex flex-wrap gap-2">
                            {handoffOptions.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => void pick(option.key)}
                                    className={cn(
                                        'da-press min-h-11 rounded-xl border px-3 text-sm font-medium',
                                        to === option.key &&
                                            'border-primary bg-primary/10',
                                    )}
                                >
                                    {option.name}
                                    {option.outside && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            · {t('handoff.outside')}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        {error && (
                            <p className="text-sm text-red-700" role="alert">
                                {error}
                            </p>
                        )}
                        {preview && (
                            <div className="space-y-2">
                                <p className="text-xs font-semibold">
                                    {t('handoff.to_technician')}
                                </p>
                                <p className="rounded-xl bg-muted p-3 text-sm whitespace-pre-line">
                                    {preview.technician}
                                </p>
                                <p className="text-xs font-semibold">
                                    {t('handoff.to_customer')}
                                </p>
                                <p className="rounded-xl bg-muted p-3 text-sm">
                                    {preview.customer ??
                                        t('handoff.no_customer_text')}
                                </p>
                            </div>
                        )}
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                className="flex-1"
                                onClick={close}
                            >
                                {t('handoff.cancel')}
                            </Button>
                            <Button
                                className="flex-1"
                                disabled={!preview || busy}
                                onClick={() =>
                                    router.post(
                                        handoff.store(jobId).url,
                                        { to },
                                        {
                                            preserveScroll: true,
                                            onSuccess: close,
                                        },
                                    )
                                }
                                data-test="handoff-confirm"
                            >
                                <Send className="size-4" />
                                {t('handoff.confirm')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
