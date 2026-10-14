import { useForm } from '@inertiajs/react';
import { FormField } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { replace, transferJobs } from '@/routes/team';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
};

export type TransitionMode = 'transfer' | 'replace';

/** Whether "Replace technician" applies: a company-only technician login that is still in use. */
export function canReplace(member: Member): boolean {
    return (
        member.role === 'technician' &&
        !member.email.endsWith('@retired.invalid')
    );
}

/**
 * "Transfer jobs" and "Replace technician" dialogs for one team member; opened from the member's "⋯" menu.
 */
export function MemberTransitions({
    member,
    members,
    mode,
    onClose,
}: {
    member: Member;
    members: Member[];
    mode: TransitionMode | null;
    onClose: () => void;
}) {
    const t = useTrans();
    const form = useForm({
        replacement_id: '',
        name: '',
        password: '',
        password_confirmation: '',
    });
    const close = () => {
        onClose();
        form.reset();
        form.clearErrors();
    };
    return (
        <>
            <Dialog
                open={mode !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        close();
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                mode === 'replace'
                                    ? 'team.replace_technician'
                                    : 'team.transfer_jobs',
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                mode === 'replace'
                                    ? 'team.replace_hint'
                                    : 'team.transfer_hint',
                                { name: member.name },
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(
                                mode === 'replace'
                                    ? replace(member.id).url
                                    : transferJobs(member.id).url,
                                {
                                    preserveScroll: true,
                                    onSuccess: close,
                                    onFinish: () =>
                                        form.reset(
                                            'password',
                                            'password_confirmation',
                                        ),
                                },
                            );
                        }}
                    >
                        {mode === 'replace' ? (
                            <>
                                <p className="text-sm">
                                    {t('team.login_kept', {
                                        email: member.email,
                                    })}
                                </p>
                                <FormField
                                    id={`replacement-name-${member.id}`}
                                    label={t('settings.profile.name')}
                                    error={form.errors.name}
                                >
                                    <Input
                                        id={`replacement-name-${member.id}`}
                                        required
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id={`replacement-password-${member.id}`}
                                    label={t('auth.fields.password')}
                                    error={form.errors.password}
                                >
                                    <Input
                                        id={`replacement-password-${member.id}`}
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        value={form.data.password}
                                        onChange={(e) =>
                                            form.setData(
                                                'password',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id={`replacement-confirmation-${member.id}`}
                                    label={t(
                                        'auth.fields.password_confirmation',
                                    )}
                                >
                                    <Input
                                        id={`replacement-confirmation-${member.id}`}
                                        type="password"
                                        autoComplete="new-password"
                                        required
                                        value={form.data.password_confirmation}
                                        onChange={(e) =>
                                            form.setData(
                                                'password_confirmation',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            </>
                        ) : (
                            <FormField
                                id={`replacement-id-${member.id}`}
                                label={t('team.transfer_to')}
                                error={form.errors.replacement_id}
                            >
                                <NativeSelect
                                    id={`replacement-id-${member.id}`}
                                    required
                                    value={form.data.replacement_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'replacement_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">
                                        {t('team.choose_replacement')}
                                    </option>
                                    {members
                                        .filter(
                                            (target) =>
                                                target.id !== member.id &&
                                                target.is_active,
                                        )
                                        .map((target) => (
                                            <option
                                                value={target.id}
                                                key={target.id}
                                            >
                                                {target.name}
                                            </option>
                                        ))}
                                </NativeSelect>
                            </FormField>
                        )}
                        <Button
                            type="submit"
                            disabled={form.processing}
                            className="h-11 w-full"
                        >
                            {t(
                                mode === 'replace'
                                    ? 'team.replace_technician'
                                    : 'team.transfer_jobs',
                            )}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
