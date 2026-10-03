import { Form, Head } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { AccountSetupSteps } from '@/components/account-setup-steps';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/lib/i18n';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

export default function VerifyEmail({
    email,
    status,
    needsCompanySetup,
}: {
    email: string;
    status?: string;
    needsCompanySetup: boolean;
}) {
    const t = useTrans();
    return (
        <>
            <Head title={t('auth.verify.title')} />
            {needsCompanySetup && <AccountSetupSteps current={2} />}
            <div className="space-y-4 rounded-2xl border bg-primary/5 p-5 text-center">
                <Mail
                    className="mx-auto size-10 text-primary"
                    aria-hidden="true"
                />
                <p className="text-sm leading-relaxed text-muted-foreground">
                    {t('auth.verify.description', { email })}
                </p>
            </div>
            {status === 'verification-link-sent' && (
                <p
                    role="status"
                    className="text-sm text-green-700 dark:text-green-400"
                >
                    {t('auth.verify.sent')}
                </p>
            )}
            <Form {...send.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        disabled={processing}
                        className="h-12 w-full rounded-xl"
                    >
                        {processing && <Spinner />}
                        {t('auth.verify.resend')}
                    </Button>
                )}
            </Form>
            <div className="flex flex-col items-center gap-3 text-sm">
                <TextLink href={edit()}>
                    {t('auth.verify.change_email')}
                </TextLink>
                <Form {...logout.form()}>
                    <Button type="submit" variant="ghost">
                        {t('auth.verify.sign_out')}
                    </Button>
                </Form>
            </div>
        </>
    );
}

VerifyEmail.layout = { title: 'auth.verify.title' };
