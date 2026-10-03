import { Form, Head, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { FormEvent } from 'react';
import { AccountSetupSteps } from '@/components/account-setup-steps';
import { FormField } from '@/components/form-field';
import { TimezoneSelect } from '@/components/timezone-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/lib/i18n';
import { logout } from '@/routes';
import { store } from '@/routes/onboarding/company';
import type { Option } from '@/types';

type Props = {
    countries: Option[];
    currencies: Option[];
    locales: Option[];
    verticals: Option[];
    timezones: Option[];
    countryDefaults: Record<
        string,
        { currency: string; locale: string; timezone: string }
    >;
    defaults: {
        country: string;
        currency: string;
        locale: string;
        timezone: string;
        vertical: string;
    };
};

export default function CompanySetup({
    countries,
    currencies,
    locales,
    verticals,
    timezones,
    countryDefaults,
    defaults,
}: Props) {
    const t = useTrans();
    const form = useForm({ name: '', ...defaults });
    const { setData } = form;
    const timezoneDetected = useRef(false);
    useEffect(() => {
        if (timezoneDetected.current) return;
        timezoneDetected.current = true;
        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (timezones.some((option) => option.value === timezone)) setData('timezone', timezone);
    }, [setData, timezones]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store().url);
    };

    return (
        <>
            <Head title={t('onboarding.title')} />
            <AccountSetupSteps current={3} />
            <p className="rounded-2xl bg-primary/5 p-4 text-sm leading-relaxed text-muted-foreground">
                {t('onboarding.explanation')}
            </p>
            <form onSubmit={submit} className="space-y-5">
                <FormField
                    id="company-name"
                    label={t('company.fields.name')}
                    error={form.errors.name}
                >
                    <Input
                        id="company-name"
                        autoComplete="organization"
                        autoFocus
                        required
                        maxLength={255}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        className="h-12 rounded-xl"
                    />
                </FormField>
                <FormField
                    id="country"
                    label={t('company.fields.country')}
                    hint={t('onboarding.country_hint')}
                    error={form.errors.country}
                >
                    <NativeSelect
                        id="country"
                        value={form.data.country}
                        onChange={(event) => {
                            const country = event.target.value;
                            form.setData((data) => ({
                                ...data,
                                country,
                                ...countryDefaults[country],
                            }));
                        }}
                        className="h-12 rounded-xl"
                    >
                        {countries.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                </FormField>
                <FormField
                    id="vertical"
                    label={t('company.fields.vertical')}
                    error={form.errors.vertical}
                >
                    <NativeSelect
                        id="vertical"
                        value={form.data.vertical}
                        onChange={(event) =>
                            form.setData('vertical', event.target.value)
                        }
                        className="h-12 rounded-xl"
                    >
                        {verticals.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </NativeSelect>
                </FormField>
                <FormField
                    id="timezone"
                    label={t('company.fields.timezone')}
                    hint={t('onboarding.timezone_hint')}
                    error={form.errors.timezone}
                >
                    <TimezoneSelect value={form.data.timezone} options={timezones} onChange={(value) => form.setData('timezone', value)} />
                </FormField>
                <details
                    className="rounded-xl border p-4"
                    open={Boolean(form.errors.currency || form.errors.locale)}
                >
                    <summary className="cursor-pointer text-sm font-medium">
                        {t('onboarding.regional_settings')}
                    </summary>
                    <div className="mt-4 grid gap-4">
                        <FormField
                            id="currency"
                            label={t('company.fields.currency')}
                            error={form.errors.currency}
                        >
                            <NativeSelect
                                id="currency"
                                value={form.data.currency}
                                onChange={(event) =>
                                    form.setData('currency', event.target.value)
                                }
                            >
                                {currencies.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
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
                                onChange={(event) =>
                                    form.setData('locale', event.target.value)
                                }
                            >
                                {locales.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                </details>
                <Button
                    type="submit"
                    disabled={form.processing}
                    className="h-12 w-full rounded-xl"
                    data-test="create-company-button"
                >
                    {form.processing && <Spinner />}
                    {t('onboarding.submit')}
                </Button>
            </form>
            <p className="text-center text-xs leading-relaxed text-muted-foreground">
                {t('onboarding.later')}
            </p>
            <Form {...logout.form()} className="text-center">
                <Button type="submit" variant="ghost">
                    {t('auth.verify.sign_out')}
                </Button>
            </Form>
        </>
    );
}

CompanySetup.layout = {
    title: 'onboarding.title',
    description: 'onboarding.description',
};
