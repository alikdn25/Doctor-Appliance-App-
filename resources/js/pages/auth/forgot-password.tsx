// Components
import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { useTrans } from '@/lib/i18n';
import { email } from '@/routes/password';

export default function ForgotPassword({ status, emailAvailable }: { status?: string; emailAvailable: boolean }) {
    const t = useTrans();

    return (
        <>
            <Head title={t('auth.forgot.title')} />

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <div className="space-y-6">
                {!emailAvailable && <p className="rounded-xl border bg-muted p-4 text-sm">{t('auth.email_unavailable')}</p>}
                {emailAvailable && <Form {...email.form()}>
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('auth.fields.email')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="email@example.com"
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                >
                                    {processing && (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    )}
                                    {t('auth.forgot.submit')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>}

                <div className="space-x-1 text-center text-sm text-muted-foreground">
                    <span>{t('auth.forgot.return_to')}</span>
                    <TextLink href={login()}>
                        {t('auth.forgot.log_in')}
                    </TextLink>
                </div>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'auth.forgot.title',
    description: 'auth.forgot.description',
};
