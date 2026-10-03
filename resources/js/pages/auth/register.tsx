import { Form, Head } from '@inertiajs/react';
import { AccountSetupSteps } from '@/components/account-setup-steps';
import { FormField } from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/lib/i18n';
import { login } from '@/routes';
import { store } from '@/routes/register';

export default function Register({
    passwordRules,
    confirmationRequired,
}: {
    passwordRules: string;
    confirmationRequired: boolean;
}) {
    const t = useTrans();
    return (
        <>
            <Head title={t('auth.register.title')} />
            <AccountSetupSteps
                current={1}
                confirmationRequired={confirmationRequired}
            />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                className="space-y-5"
            >
                {({ processing, errors }) => (
                    <>
                        <FormField
                            id="name"
                            label={t('auth.fields.name')}
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                autoComplete="name"
                                autoFocus
                                required
                                maxLength={255}
                                className="h-12 rounded-xl"
                            />
                        </FormField>
                        <FormField
                            id="email"
                            label={t('auth.fields.email')}
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                required
                                maxLength={255}
                                className="h-12 rounded-xl"
                            />
                        </FormField>
                        <FormField
                            id="password"
                            label={t('auth.fields.password')}
                            error={errors.password}
                        >
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                required
                                passwordrules={passwordRules}
                                className="h-12 rounded-xl"
                            />
                        </FormField>
                        <FormField
                            id="password_confirmation"
                            label={t('auth.fields.password_confirmation')}
                            error={errors.password_confirmation}
                        >
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                required
                                className="h-12 rounded-xl"
                            />
                        </FormField>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="h-12 w-full rounded-xl"
                            data-test="register-button"
                        >
                            {processing && <Spinner />}
                            {t('auth.register.submit')}
                        </Button>
                    </>
                )}
            </Form>
            <p className="text-center text-sm text-muted-foreground">
                {t('auth.register.already_registered')}{' '}
                <TextLink href={login()}>{t('auth.login.title')}</TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'auth.register.heading',
    description: 'auth.register.description',
};
