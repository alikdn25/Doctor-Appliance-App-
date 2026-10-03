import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { TimezoneSelect } from '@/components/timezone-select';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { index, store } from '@/routes/admin/companies';
import type { Option } from '@/types';

type Props = {
    emailAvailable: boolean;
    timezones: Option[];
    countries: Option[];
    countryDefaults: Record<string, { currency: string; locale: string }>;
    currencies: Option[];
    locales: Option[];
    verticals: Option[];
    subscriptionStatuses: Option[];
    defaults: {
        timezone: string;
        country: string;
        currency: string;
        locale: string;
        vertical: string;
    };
};

export default function AdminCompanyCreate({
    emailAvailable,
    timezones,
    countries,
    countryDefaults,
    currencies,
    locales,
    verticals,
    subscriptionStatuses,
    defaults,
}: Props) {
    const t = useTrans();
    const form = useForm({
        name: '',
        country: defaults.country,
        vertical: defaults.vertical,
        timezone: defaults.timezone,
        currency: defaults.currency,
        locale: defaults.locale,
        plan: '',
        subscription_status: 'trialing',
        owner_name: '',
        owner_email: '',
        owner_password: '',
        owner_password_confirmation: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url, { onSuccess: () => form.reset('owner_password', 'owner_password_confirmation') });
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
                        id="country"
                        label={t('company.fields.country')}
                        hint={t('admin.companies.country_hint')}
                        error={form.errors.country}
                    >
                        <NativeSelect
                            id="country"
                            value={form.data.country}
                            onChange={(e) => {
                                // The country sets currency and regional format; both stay editable.
                                const preset = countryDefaults[e.target.value];
                                form.setData({
                                    ...form.data,
                                    country: e.target.value,
                                    currency:
                                        preset?.currency ?? form.data.currency,
                                    locale: preset?.locale ?? form.data.locale,
                                });
                            }}
                        >
                            {countries.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="vertical"
                        label={t('company.fields.vertical')}
                        hint={t('admin.companies.vertical_hint')}
                        error={form.errors.vertical}
                    >
                        <NativeSelect
                            id="vertical"
                            value={form.data.vertical}
                            onChange={(e) =>
                                form.setData('vertical', e.target.value)
                            }
                        >
                            {verticals.map((v) => (
                                <option key={v.value} value={v.value}>
                                    {v.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="locale"
                        label={t('company.fields.locale')}
                        error={form.errors.locale}
                    >
                        <NativeSelect
                            id="locale"
                            value={form.data.locale}
                            onChange={(e) =>
                                form.setData('locale', e.target.value)
                            }
                        >
                            {locales.map((l) => (
                                <option key={l.value} value={l.value}>
                                    {l.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="timezone"
                        label={t('company.fields.timezone')}
                        error={form.errors.timezone}
                    >
                        <TimezoneSelect
                            value={form.data.timezone}
                            options={timezones}
                            onChange={(value) =>
                                form.setData('timezone', value)
                            }
                        />
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
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
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

                {!emailAvailable && <>
                    <p className="text-sm text-muted-foreground">{t('team.manual_access')}</p>
                    <FormField id="owner_password" label={t('team.initial_password')} error={form.errors.owner_password}>
                        <Input id="owner_password" type="password" autoComplete="new-password" value={form.data.owner_password} onChange={(e) => form.setData('owner_password', e.target.value)} />
                    </FormField>
                    <FormField id="owner_password_confirmation" label={t('team.confirm_password')} error={form.errors.owner_password_confirmation}>
                        <Input id="owner_password_confirmation" type="password" autoComplete="new-password" value={form.data.owner_password_confirmation} onChange={(e) => form.setData('owner_password_confirmation', e.target.value)} />
                    </FormField>
                </>}

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
