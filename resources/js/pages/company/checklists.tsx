import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/checklists';
import type { Option } from '@/types';

type Props = {
    jobTypes: Option[];
    templates: Record<string, string[]>;
};

/**
 * Checklist per job type, edited as one item per line.
 */
export default function Checklists({ jobTypes, templates }: Props) {
    const t = useTrans();
    const form = useForm<{ templates: Record<string, string> }>({
        templates: Object.fromEntries(
            jobTypes.map((type) => [
                type.value,
                (templates[type.value] ?? []).join('\n'),
            ]),
        ),
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            templates: Object.fromEntries(
                Object.entries(data.templates).map(([type, text]) => [
                    type,
                    text.split('\n'),
                ]),
            ),
        }));
        form.put(update().url, { preserveScroll: true });
    };

    const errorFor = (type: string) =>
        errors[`templates.${type}`] ??
        Object.entries(errors).find(([key]) =>
            key.startsWith(`templates.${type}.`),
        )?.[1];

    return (
        <>
            <Head title={t('checklists.title')} />

            <form onSubmit={submit} className="max-w-2xl space-y-6 p-4">
                <PageHeader
                    title={t('checklists.title')}
                    description={t('checklists.description')}
                />
                <p className="text-sm text-muted-foreground">
                    {t('checklists.hint')}
                </p>

                {jobTypes.map((type) => (
                    <FormField
                        key={type.value}
                        id={`checklist-${type.value}`}
                        label={type.label}
                        error={errorFor(type.value)}
                    >
                        <Textarea
                            id={`checklist-${type.value}`}
                            rows={5}
                            value={form.data.templates[type.value]}
                            onChange={(e) =>
                                form.setData('templates', {
                                    ...form.data.templates,
                                    [type.value]: e.target.value,
                                })
                            }
                        />
                    </FormField>
                ))}

                <Button type="submit" size="lg" disabled={form.processing}>
                    {t('common.save')}
                </Button>
            </form>
        </>
    );
}

Checklists.layout = {
    breadcrumbs: [{ title: 'checklists.title', href: edit() }],
};
