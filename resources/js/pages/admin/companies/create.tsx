import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { index, store } from '@/routes/admin/companies';
import type { Option } from '@/types';

type Props = {
    timezones: string[];
    currencies: string[];
    subscriptionStatuses: Option[];
    defaults: { timezone: string; currency: string };
};

export default function AdminCompanyCreate({
    timezones,
    currencies,
    subscriptionStatuses,
    defaults,
}: Props) {
    const t = useTrans();
    const form = useForm({
        name: '',
        timezone: defaults.timezone,
        currency: defaults.currency,
        plan: '',
        subscription_status: 'trialing',
        owner_name: '',
        owner_email: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url);
    };

    return (
        <>
            <Head title={t('admin.companies.add')} />

            <form
                onSubmit={submit}
                className="flex max-w-xl flex-col gap-4 p-4"
            >
                <PageHeader
                    title={t('admin.companies.add')}
                    description={t('admin.companies.add_description')}
                />

                <FormField
                    id="name"
                    label={t('company.fields.name')}
                    error={form.errors.name}
                >
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="timezone"
                        label={t('company.fields.timezone')}
                        error={form.errors.timezone}
                    >
                        <NativeSelect
                            id="timezone"
                            value={form.data.timezone}
                            onChange={(e) =>
                                form.setData('timezone', e.target.value)
                            }
                        >
                            <option value="">
                                {t('admin.timezone_from_owner')}
                            </option>
                            {timezones.map((tz) => (
                                <option key={tz}>{tz}</option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="currency"
                        label={t('company.fields.currency')}
                        error={form.errors.currency}
                    >
                        <NativeSelect
                            id="currency"
                            value={form.data.currency}
                            onChange={(e) =>
                                form.setData('currency', e.target.value)
                            }
                        >
                            {currencies.map((c) => (
                                <option key={c}>{c}</option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="plan"
                        label={t('admin.fields.plan')}
                        error={form.errors.plan}
                    >
                        <Input
                            id="plan"
                            value={form.data.plan}
                            onChange={(e) =>
                                form.setData('plan', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="subscription_status"
                        label={t('admin.fields.subscription_status')}
                        error={form.errors.subscription_status}
                    >
                        <NativeSelect
                            id="subscription_status"
                            value={form.data.subscription_status}
                            onChange={(e) =>
                                form.setData(
                                    'subscription_status',
                                    e.target.value,
                                )
                            }
                        >
                            {subscriptionStatuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                </div>

                <h2 className="mt-2 text-base font-medium">
                    {t('admin.companies.owner')}
                </h2>
                <FormField
                    id="owner_name"
                    label={t('team.fields.name')}
                    error={form.errors.owner_name}
                >
                    <Input
                        id="owner_name"
                        value={form.data.owner_name}
                        onChange={(e) =>
                            form.setData('owner_name', e.target.value)
                        }
                        required
                    />
                </FormField>
                <FormField
                    id="owner_email"
                    label={t('team.fields.email')}
                    error={form.errors.owner_email}
                    hint={t('admin.companies.owner_hint')}
                >
                    <Input
                        id="owner_email"
                        type="email"
                        value={form.data.owner_email}
                        onChange={(e) =>
                            form.setData('owner_email', e.target.value)
                        }
                        required
                    />
                </FormField>

                <Button
                    type="submit"
                    disabled={form.processing}
                    className="sm:w-fit"
                >
                    {t('admin.companies.create')}
                </Button>
            </form>
        </>
    );
}

AdminCompanyCreate.layout = {
    breadcrumbs: [{ title: 'admin.companies.title', href: index() }],
};
