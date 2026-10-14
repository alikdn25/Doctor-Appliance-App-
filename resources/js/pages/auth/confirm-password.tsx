import { Form, Head, router } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTrans } from '@/lib/i18n';
import { store } from '@/routes/password/confirm';
import { edit } from '@/routes/profile';

export default function ConfirmPassword() {
    const t = useTrans();
    // Back to where the person came from; a direct visit goes to the profile instead of a dead end.
    const cancel = () => {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            router.visit(edit());
        }
    };

    return (
        <>
            <Head title={t('auth.confirm.title')} />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {t('auth.fields.password')}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder={t('auth.fields.password')}
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Button
                                className="h-11 w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                {t('auth.confirm.submit')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-11 w-full"
                                onClick={cancel}
                            >
                                {t('auth.confirm.cancel')}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'auth.confirm.title',
    description: 'auth.confirm.description',
};
