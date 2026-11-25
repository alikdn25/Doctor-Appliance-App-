import { Head, router, useForm } from '@inertiajs/react';
import { Download, FileText, Mail, MessageSquare, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { ReportPhotos, ReportText } from '@/components/reports/report-editor';
import type {
    JobPhotoOption,
    ReportPhotoItem,
} from '@/components/reports/report-editor';
import { ScreenHeader } from '@/components/screen-header';
import { SignaturePad } from '@/components/signature-pad';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { useCompanyTime } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';

type Values = {
    brand_address_id: number | null;
    customer_name: string;
    address: string;
    appliance_type: string;
    appliance_brand: string;
    appliance_model: string;
    appliance_serial: string;
    report_date: string;
    technician_name: string;
    description: string;
    signer_name: string;
    photos: ReportPhotoItem[];
};

type Saved = {
    id: number;
    pdf_url: string;
    signature_url: string | null;
    sent_at: string | null;
    sent_to: string | null;
} | null;

/**
 * The job's own report (owner's task of November 25): filled from the job, every field can be changed and none is
 * required. Text with the microphone, translation and "Write with AI"; photos; an optional customer signature.
 * Saved under the job's Files; Preview / Download PDF, send by email (PDF attached) or SMS (a link).
 */
export default function ReportForm({
    job,
    report,
    values,
    locations,
    jobPhotos: initialJobPhotos,
    customerEmail,
    customerPhone,
}: {
    job: { id: number; number: number; url: string };
    report: Saved;
    values: Values;
    locations: { id: number; label: string; distance: string | null }[];
    jobPhotos: JobPhotoOption[];
    customerEmail: string | null;
    customerPhone: string | null;
}) {
    const t = useTrans();
    const time = useCompanyTime();
    const form = useForm({
        ...values,
        signature: '' as string,
        clear_signature: false,
    });
    const [jobPhotos, setJobPhotos] = useState(initialJobPhotos);
    const canvas = useRef<HTMLCanvasElement | null>(null);
    const [signed, setSigned] = useState(false);
    const [email, setEmail] = useState(customerEmail ?? '');
    const [sending, setSending] = useState<null | 'email' | 'sms'>(null);
    const data = form.data;

    const submit = (event?: FormEvent) => {
        event?.preventDefault();
        form.transform((d) => ({
            ...d,
            photos: d.photos.map((p) => ({
                job_photo_id: p.job_photo_id,
                caption: p.caption,
            })),
            signature:
                signed && canvas.current
                    ? canvas.current.toDataURL('image/png')
                    : '',
        }));
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setSigned(false);
                form.setData('clear_signature', false);
            },
        };
        if (report) form.put(`/job-reports/${report.id}`, options);
        else form.post(`/jobs/${job.id}/reports`, options);
    };

    const send = (channel: 'email' | 'sms') => {
        if (!report) return;
        setSending(channel);
        router.post(
            `/job-reports/${report.id}/send`,
            { channel, to: channel === 'email' ? email : null },
            { preserveScroll: true, onFinish: () => setSending(null) },
        );
    };

    const field = (
        key:
            | 'customer_name'
            | 'address'
            | 'appliance_type'
            | 'appliance_brand'
            | 'appliance_model'
            | 'appliance_serial'
            | 'technician_name',
        label: string,
    ) => (
        <FormField id={`report-${key}`} label={label} error={form.errors[key]}>
            <Input
                id={`report-${key}`}
                value={data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
                data-test={`report-${key}`}
            />
        </FormField>
    );

    return (
        <>
            <Head
                title={
                    report
                        ? t('service_reports.edit')
                        : t('service_reports.new')
                }
            />
            <ScreenHeader
                title={`${report ? t('service_reports.edit') : t('service_reports.new')} · #${job.number}`}
                back={job.url}
            />

            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-2xl space-y-4 p-4 pb-32 sm:p-6"
                data-test="report-form"
            >
                <section className="da-card space-y-3 p-4">
                    <FormField
                        id="report-location"
                        label={t('service_reports.location')}
                    >
                        <NativeSelect
                            id="report-location"
                            value={data.brand_address_id ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'brand_address_id',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                            data-test="report-location"
                        >
                            <option value="">
                                {t('service_reports.no_location')}
                            </option>
                            {locations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.distance
                                        ? `${l.label} — ${t('service_reports.distance', { distance: l.distance })}`
                                        : l.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    {field('customer_name', t('service_reports.customer'))}
                    {field('address', t('service_reports.address'))}
                    <div className="grid grid-cols-2 gap-3">
                        {field(
                            'appliance_type',
                            t('service_reports.appliance_type'),
                        )}
                        {field('appliance_brand', t('service_reports.brand'))}
                        {field('appliance_model', t('service_reports.model'))}
                        {field('appliance_serial', t('service_reports.serial'))}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <FormField
                            id="report-date"
                            label={t('service_reports.date')}
                            error={form.errors.report_date}
                        >
                            <Input
                                id="report-date"
                                type="date"
                                value={data.report_date}
                                onChange={(e) =>
                                    form.setData('report_date', e.target.value)
                                }
                            />
                        </FormField>
                        {field(
                            'technician_name',
                            t('service_reports.technician'),
                        )}
                    </div>
                </section>

                <section className="da-card space-y-3 p-4">
                    <FormField
                        id="report-description"
                        label={t('service_reports.description')}
                        error={form.errors.description}
                    >
                        <ReportText
                            id="report-description"
                            value={data.description}
                            jobId={job.id}
                            onChange={(text) =>
                                form.setData('description', text)
                            }
                        />
                    </FormField>
                    <h3 className="text-sm font-semibold">
                        {t('service_reports.photos')}
                    </h3>
                    <ReportPhotos
                        jobId={job.id}
                        value={data.photos}
                        jobPhotos={jobPhotos}
                        onJobPhotosChange={setJobPhotos}
                        onChange={(photos) => form.setData('photos', photos)}
                    />
                </section>

                <section className="da-card space-y-3 p-4">
                    <h3 className="text-sm font-semibold">
                        {t('service_reports.signature')}
                    </h3>
                    {report?.signature_url && !data.clear_signature && (
                        <img
                            src={report.signature_url}
                            alt=""
                            className="max-h-24 rounded-lg border bg-white"
                        />
                    )}
                    <FormField
                        id="report-signer"
                        label={t('service_reports.signer_name')}
                    >
                        <Input
                            id="report-signer"
                            value={data.signer_name}
                            onChange={(e) =>
                                form.setData('signer_name', e.target.value)
                            }
                        />
                    </FormField>
                    <SignaturePad
                        canvasRef={canvas}
                        onChange={(empty) => setSigned(!empty)}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            const c = canvas.current;
                            c?.getContext('2d')?.clearRect(
                                0,
                                0,
                                c.width,
                                c.height,
                            );
                            setSigned(false);
                            form.setData('clear_signature', true);
                        }}
                    >
                        {t('service_reports.signature_clear')}
                    </Button>
                </section>

                {report && (
                    <section
                        className="da-card space-y-3 p-4"
                        data-test="report-send"
                    >
                        <p className="text-sm text-muted-foreground">
                            {report.sent_at
                                ? t('service_reports.sent_at', {
                                      date: time.dateTime(report.sent_at),
                                      to: report.sent_to ?? '',
                                  })
                                : t('service_reports.not_sent_yet')}
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <Button asChild variant="outline">
                                <a
                                    href={report.pdf_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    data-test="report-preview-pdf"
                                >
                                    <FileText />{' '}
                                    {t('service_reports.preview_pdf')}
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a
                                    href={`${report.pdf_url}?download=1`}
                                    data-test="report-download-pdf"
                                >
                                    <Download />{' '}
                                    {t('service_reports.download_pdf')}
                                </a>
                            </Button>
                        </div>
                        <FormField
                            id="report-email"
                            label={t('service_reports.email_to')}
                            error={(form.errors as Record<string, string>).to}
                        >
                            <Input
                                id="report-email"
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                            />
                        </FormField>
                        <div className="grid grid-cols-2 gap-2">
                            <Button
                                type="button"
                                loading={sending === 'email'}
                                onClick={() => send('email')}
                                data-test="report-send-email"
                            >
                                <Mail /> {t('service_reports.send_email')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                loading={sending === 'sms'}
                                disabled={!customerPhone}
                                onClick={() => send('sms')}
                                data-test="report-send-sms"
                            >
                                <MessageSquare />{' '}
                                {t('service_reports.send_sms')}
                            </Button>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            className="w-full text-destructive"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        t('service_reports.confirm_delete'),
                                    )
                                ) {
                                    router.delete(`/job-reports/${report.id}`);
                                }
                            }}
                        >
                            <Trash2 /> {t('service_reports.delete')}
                        </Button>
                    </section>
                )}

                <StickyActionBar>
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        loading={form.processing}
                        data-test="report-save"
                    >
                        {t('common.save')}
                    </Button>
                </StickyActionBar>
            </form>
        </>
    );
}
