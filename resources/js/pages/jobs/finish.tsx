import { Head, Link, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    Ellipsis,
    MapPin,
    MessageSquare,
    Phone,
    Wrench,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { applianceImageUrl } from '@/components/appliance-image';
import { useMoney } from '@/components/billing/money';
import { CustomerAvatar } from '@/components/customers/customer-avatar';
import type { AvatarIcon } from '@/components/customers/customer-avatar';
import { telUrl } from '@/components/customers/types';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import type {
    CallbackInfo,
    ClosureReasons,
} from '@/components/jobs/finish-dialog';
import { PhotoSection } from '@/components/jobs/photo-section';
import type { JobPhotoData } from '@/components/jobs/photo-section';
import type { ApplianceItem } from '@/components/jobs/types';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import { show as showAppliance } from '@/routes/appliances';
import { mine, show } from '@/routes/jobs';
import { finish } from '@/routes/visits';
import type { Option } from '@/types';

type Result = 'completed' | 'parts' | 'not_completed';
type NoRepair = 'customer_declined' | 'unable_to_repair' | 'no_charge';

const NOTES_MAX = 500;

/**
 * Finish visit (approved mockup): customer, appliance, one free-text "Work completed / notes" box, photos and
 * the job result (Completed / Part needed / Not completed), then one Finish visit button.
 */
export default function FinishVisit({
    visitId,
    job,
    photoKinds,
    closureReasons,
    callback,
}: {
    visitId: number;
    job: {
        id: number;
        number: number;
        tech_notes: string | null;
        customer: {
            display_name: string;
            avatar_icon: AvatarIcon;
            phone: string | null;
        };
        address: string | null;
        unit: string | null;
        gate_code: string | null;
        appliances: ApplianceItem[];
        photos: JobPhotoData[];
    };
    photoKinds: Option[];
    closureReasons: ClosureReasons;
    callback: CallbackInfo;
}) {
    const t = useTrans();
    const phoneText = usePhone();
    const money = useMoney(callback?.currency);
    const form = useForm({
        result: 'completed' as Result,
        no_repair: 'customer_declined' as NoRepair,
        reason: '',
        invoice_diagnosis: !callback,
        refund: 'none',
        refund_amount: '',
        refund_reason: '',
        tech_notes: job.tech_notes ?? '',
    });
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const canRefund =
        callback !== null &&
        callback.refundable > 0 &&
        data.result === 'not_completed' &&
        data.no_repair !== 'no_charge';

    const outcome =
        data.result === 'completed'
            ? callback
                ? 'fixed_under_warranty'
                : 'completed'
            : data.result === 'parts'
              ? 'waiting_for_parts'
              : data.no_repair;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            outcome,
            reason: d.result === 'not_completed' ? d.reason : '',
            note: '',
            invoice_diagnosis:
                d.result === 'not_completed' && d.no_repair !== 'no_charge'
                    ? d.invoice_diagnosis
                    : false,
            refund: canRefund ? d.refund : 'none',
            refund_amount: d.refund_amount,
            refund_reason: d.refund_reason,
            tech_notes: d.tech_notes,
        }));
        form.post(finish(visitId).url);
    };

    const results: { key: Result; title: string; hint: string }[] = [
        {
            key: 'completed',
            title: t('jobs.finish_screen.completed'),
            hint: t(
                callback
                    ? 'jobs.finish_screen.completed_warranty_hint'
                    : 'jobs.finish_screen.completed_hint',
            ),
        },
        {
            key: 'parts',
            title: t('jobs.finish_screen.parts'),
            hint: t('jobs.finish_screen.parts_hint'),
        },
        {
            key: 'not_completed',
            title: t('jobs.finish_screen.not_completed'),
            hint: t('jobs.finish_screen.not_completed_hint'),
        },
    ];

    const extras = [
        job.unit && t('jobs.finish_screen.unit', { unit: job.unit }),
        job.gate_code &&
            t('jobs.finish_screen.buzzer', { code: job.gate_code }),
    ].filter(Boolean);

    return (
        <>
            <Head title={t('jobs.finish_screen.title')} />

            <ScreenHeader
                title={t('jobs.finish_screen.title')}
                subtitle={`#${job.number}`}
                back={show(job.id)}
                actions={
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className={headerButtonClass}
                                aria-label={t('jobs.finish_screen.more')}
                            >
                                <Ellipsis className="size-6" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <Link href={show(job.id)}>
                                    {t('jobs.finish_screen.open_job')}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                }
            />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-3 p-4 sm:p-6"
            >
                {/* Customer */}
                <section className="da-card flex items-start gap-3 p-3">
                    <CustomerAvatar
                        icon={job.customer.avatar_icon}
                        name={job.customer.display_name}
                        size="xl"
                    />
                    <div className="min-w-0 flex-1 space-y-1 text-[15px]">
                        <div className="flex items-start justify-between gap-2">
                            <div className="pt-1 text-lg font-bold">
                                {job.customer.display_name}
                            </div>
                            {job.customer.phone && (
                                <div className="flex shrink-0 gap-2">
                                    <a
                                        href={telUrl(job.customer.phone)}
                                        aria-label={t('jobs.call')}
                                        className="da-soft da-press flex size-11 items-center justify-center rounded-[14px]"
                                    >
                                        <Phone className="size-5 fill-[#0A6CF5] text-[#0A6CF5]" />
                                    </a>
                                    <a
                                        href={`sms:${job.customer.phone.replace(/[^\d+]/g, '')}`}
                                        aria-label={t('jobs.text')}
                                        className="da-soft da-press flex size-11 items-center justify-center rounded-[14px]"
                                    >
                                        <MessageSquare className="size-5 fill-[#0A6CF5] text-[#0A6CF5]" />
                                    </a>
                                </div>
                            )}
                        </div>
                        {job.customer.phone && (
                            <div className="flex items-center gap-1.5">
                                <Phone className="size-4 shrink-0 text-[#334155]" />
                                {phoneText(job.customer.phone)}
                            </div>
                        )}
                        <div className="flex items-start gap-1.5">
                            <MapPin className="mt-0.5 size-4 shrink-0 text-[#334155]" />
                            {job.address || t('jobs.quick.address_pending')}
                        </div>
                        {extras.length > 0 && (
                            <div className="pl-[22px] text-muted-foreground">
                                {extras.join(' • ')}
                            </div>
                        )}
                    </div>
                </section>

                {/* Appliances */}
                {job.appliances
                    .filter((a) => !a.removed)
                    .map((a) => (
                        <section
                            key={a.id}
                            className="da-card flex items-center gap-3 p-3"
                        >
                            <img
                                src={applianceImageUrl(a.type)}
                                alt=""
                                className="h-20 w-20 shrink-0 object-contain mix-blend-multiply"
                            />
                            <div className="min-w-0 flex-1">
                                <div className="text-lg font-bold">
                                    {a.type_label}
                                </div>
                                <div className="truncate text-[15px]">
                                    {[a.manufacturer, a.model_number]
                                        .filter(Boolean)
                                        .join(' ')}
                                </div>
                            </div>
                            <Button
                                asChild
                                variant="secondary"
                                size="sm"
                                className="h-10"
                            >
                                <Link href={showAppliance(a.id)}>
                                    {t('jobs.finish_screen.view_details')}
                                    <ChevronRight />
                                </Link>
                            </Button>
                        </section>
                    ))}

                {/* Work completed / notes: one free-text box */}
                <section className="da-card space-y-2 p-3">
                    <label
                        htmlFor="finish-work"
                        className="flex items-center gap-2 text-base font-bold"
                    >
                        <Wrench className="size-5" />
                        {t('jobs.finish_screen.work')}
                        <span className="text-sm font-normal text-muted-foreground">
                            {t('jobs.finish_screen.optional')}
                        </span>
                    </label>
                    <Textarea
                        id="finish-work"
                        rows={5}
                        maxLength={NOTES_MAX}
                        placeholder={t('jobs.finish_screen.work_hint')}
                        value={data.tech_notes}
                        onChange={(e) =>
                            form.setData('tech_notes', e.target.value)
                        }
                    />
                    <div className="text-right text-xs text-muted-foreground tabular-nums">
                        {data.tech_notes.length}/{NOTES_MAX}
                    </div>
                    <InputError message={errors.tech_notes} />
                </section>

                {/* Photos */}
                <section className="da-card p-3">
                    <PhotoSection
                        jobId={job.id}
                        visitId={visitId}
                        photos={job.photos}
                        kinds={photoKinds}
                        canAdd
                        compact
                    />
                </section>

                {/* Job result */}
                <section
                    className="da-card space-y-2 p-3"
                    aria-labelledby="finish-result"
                >
                    <h2
                        id="finish-result"
                        className="flex items-center gap-2 text-base font-bold"
                    >
                        <span className="flex size-6 items-center justify-center rounded-full bg-[#0F1B2D] text-white">
                            <Check className="size-4" strokeWidth={3} />
                        </span>
                        {t('jobs.finish_screen.result')}
                    </h2>
                    <div role="radiogroup" className="space-y-2">
                        {results.map((r) => {
                            const on = data.result === r.key;
                            return (
                                <button
                                    key={r.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={on}
                                    onClick={() =>
                                        form.setData('result', r.key)
                                    }
                                    className={cn(
                                        'da-press flex min-h-16 w-full items-center gap-3 rounded-[18px] border-2 px-3 py-2 text-left',
                                        on
                                            ? 'border-[#0A6CF5] bg-[linear-gradient(90deg,#F2F8FF,#CFE1FA)]'
                                            : 'border-[#E1E8F2] bg-[linear-gradient(90deg,#FFFFFF,#EDF2F9)]',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                                            on
                                                ? 'border-[#0A6CF5]'
                                                : 'border-[#A9B6C8]',
                                        )}
                                    >
                                        {on && (
                                            <span className="da-primary size-3 rounded-full" />
                                        )}
                                    </span>
                                    <span className="flex-1">
                                        <span className="block font-bold">
                                            {r.title}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {r.hint}
                                        </span>
                                    </span>
                                    {r.key === 'completed' ? (
                                        on && (
                                            <span className="da-primary flex size-7 shrink-0 items-center justify-center rounded-full">
                                                <Check
                                                    className="size-4"
                                                    strokeWidth={3}
                                                />
                                            </span>
                                        )
                                    ) : (
                                        <ChevronRight
                                            className={cn(
                                                'size-5 shrink-0 text-[#5B6779] transition-transform',
                                                on && 'rotate-90',
                                            )}
                                            aria-hidden="true"
                                        />
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    {data.result === 'not_completed' && (
                        <div className="space-y-3 border-l-4 border-[#CBD5E1] pl-3">
                            <div className="grid gap-2">
                                {(
                                    [
                                        'customer_declined',
                                        'unable_to_repair',
                                        'no_charge',
                                    ] as const
                                ).map((key) => (
                                    <Button
                                        key={key}
                                        type="button"
                                        variant={
                                            data.no_repair === key
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        aria-pressed={data.no_repair === key}
                                        className={cn(
                                            'h-12 justify-start',
                                            data.no_repair === key &&
                                                'ring-2 ring-[#0A6CF5]',
                                        )}
                                        onClick={() =>
                                            form.setData((d) => ({
                                                ...d,
                                                no_repair: key,
                                                reason: '',
                                            }))
                                        }
                                    >
                                        {t(`jobs.outcomes.${key}`)}
                                    </Button>
                                ))}
                            </div>
                            <FormField
                                id="finish-reason"
                                label={t('jobs.close.reason')}
                                error={errors.reason}
                            >
                                <NativeSelect
                                    id="finish-reason"
                                    value={data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                >
                                    <option value="">
                                        {t('jobs.close.pick_reason')}
                                    </option>
                                    {closureReasons[data.no_repair].map((r) => (
                                        <option key={r} value={r}>
                                            {r}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            {canRefund && callback && (
                                <fieldset className="space-y-2 rounded-md border p-3">
                                    <legend className="px-1 text-sm font-medium">
                                        {t('jobs.callback.refund')}
                                    </legend>
                                    <NativeSelect
                                        aria-label={t('jobs.callback.refund')}
                                        value={data.refund}
                                        onChange={(e) =>
                                            form.setData(
                                                'refund',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="none">
                                            {t('payments.refunds.none')}
                                        </option>
                                        <option value="full">
                                            {t('payments.refunds.full', {
                                                amount: money(
                                                    callback.refundable,
                                                ),
                                            })}
                                        </option>
                                        <option value="partial">
                                            {t('payments.refunds.partial')}
                                        </option>
                                    </NativeSelect>
                                    {data.refund === 'partial' && (
                                        <Input
                                            aria-label={t(
                                                'payments.refunds.amount',
                                            )}
                                            inputMode="decimal"
                                            value={data.refund_amount}
                                            onChange={(e) =>
                                                form.setData(
                                                    'refund_amount',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    )}
                                    {data.refund !== 'none' && (
                                        <Input
                                            aria-label={t(
                                                'jobs.callback.refund_reason',
                                            )}
                                            placeholder={t(
                                                'jobs.callback.refund_reason',
                                            )}
                                            maxLength={500}
                                            value={data.refund_reason}
                                            onChange={(e) =>
                                                form.setData(
                                                    'refund_reason',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    )}
                                    <InputError
                                        message={
                                            errors.refund_amount ??
                                            errors.refund_reason
                                        }
                                    />
                                </fieldset>
                            )}
                            {data.no_repair !== 'no_charge' && (
                                <label className="flex min-h-10 items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.invoice_diagnosis}
                                        onCheckedChange={(c) =>
                                            form.setData(
                                                'invoice_diagnosis',
                                                c === true,
                                            )
                                        }
                                    />
                                    {t('jobs.close.invoice_diagnosis')}
                                </label>
                            )}
                        </div>
                    )}
                    <InputError message={errors.outcome ?? errors.status} />
                </section>

                <Button
                    type="submit"
                    className="h-14 w-full text-lg"
                    disabled={
                        form.processing ||
                        (data.result === 'not_completed' && data.reason === '')
                    }
                >
                    <span className="flex size-8 items-center justify-center rounded-full bg-white text-[#0567F5]">
                        <Check className="size-5" strokeWidth={3} />
                    </span>
                    {t('jobs.finish_screen.title')}
                </Button>
            </form>
        </>
    );
}

FinishVisit.layout = {
    breadcrumbs: [{ title: 'jobs.my_jobs', href: mine() }],
};
