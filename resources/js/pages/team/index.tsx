import { Head, router, useForm } from '@inertiajs/react';
import { Mail, Pencil, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormField } from '@/components/form-field';
import { MemberTransitions } from '@/components/member-transitions';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { destroy, index, resendInvitation, store, update } from '@/routes/team';
import type { Option } from '@/types';

type Member = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    is_active: boolean;
    brand_ids: number[];
    invitation_pending: boolean;
    is_self: boolean;
    can_manage: boolean;
};

type Props = {
    members: Member[];
    roles: Option[];
    brands: { id: number; name: string }[];
};

type MemberForm = {
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    brand_ids: number[];
};

export default function TeamIndex({ members, roles, brands }: Props) {
    const t = useTrans();
    const [editing, setEditing] = useState<Member | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<MemberForm>({
        name: '',
        email: '',
        role: 'technician',
        is_active: true,
        brand_ids: [],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const openCreate = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (member: Member) => {
        setEditing(member);
        form.clearErrors();
        form.setData({
            name: member.name,
            email: member.email,
            role: member.role,
            is_active: member.is_active,
            brand_ids: member.brand_ids,
        });
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            form.put(update(editing.id).url, options);
        } else {
            form.post(store().url, options);
        }
    };

    const toggleBrand = (id: number, checked: boolean) =>
        form.setData(
            'brand_ids',
            checked
                ? [...form.data.brand_ids, id]
                : form.data.brand_ids.filter((b) => b !== id),
        );

    const remove = (member: Member) => {
        if (confirm(t('team.confirm_remove', { name: member.name }))) {
            router.delete(destroy(member.id).url, { preserveScroll: true });
        }
    };

    const brandNames = (ids: number[]) =>
        ids.length === 0
            ? t('team.all_brands')
            : brands
                  .filter((b) => ids.includes(b.id))
                  .map((b) => b.name)
                  .join(', ');

    return (
        <>
            <Head title={t('team.title')} />

            <div className="p-4">
                <PageHeader
                    title={t('team.title')}
                    description={t('team.description')}
                    actions={
                        <Button onClick={openCreate}>
                            <Plus /> {t('team.add')}
                        </Button>
                    }
                />

                <InputError message={errors.member} className="mb-3" />

                <ul className="divide-y rounded-lg border">
                    {members.map((member) => (
                        <li
                            key={member.id}
                            className="flex flex-wrap items-center gap-3 px-4 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {member.name}
                                    </span>
                                    <Badge variant="outline">
                                        {member.role_label}
                                    </Badge>
                                    {!member.is_active && (
                                        <Badge variant="secondary">
                                            {t('common.inactive')}
                                        </Badge>
                                    )}
                                    {member.invitation_pending && (
                                        <Badge variant="secondary">
                                            {t('team.invited')}
                                        </Badge>
                                    )}
                                </div>
                                <div className="text-xs break-words text-muted-foreground">
                                    {member.email.endsWith('@retired.invalid') ? t('team.retired_login') : member.email} ·{' '}
                                    {brandNames(member.brand_ids)}
                                </div>
                            </div>
                            {member.can_manage && (
                                <div className="flex flex-wrap gap-2">
                                    <MemberTransitions member={member} members={members} />
                                    {member.invitation_pending && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t(
                                                'team.resend_invitation',
                                            )}
                                            title={t('team.resend_invitation')}
                                            onClick={() =>
                                                router.post(
                                                    resendInvitation(member.id)
                                                        .url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Mail />
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-10"
                                        aria-label={t('common.edit')}
                                        onClick={() => openEdit(member)}
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-10"
                                        aria-label={t('common.remove')}
                                        onClick={() => remove(member)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? editing.name : t('team.add')}
                        </DialogTitle>
                        <DialogDescription>
                            {editing
                                ? editing.email
                                : t('team.add_description')}
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={submit} className="grid gap-4">
                        {!editing && (
                            <>
                                <FormField
                                    id="name"
                                    label={t('team.fields.name')}
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                        required
                                    />
                                </FormField>
                                <FormField
                                    id="email"
                                    label={t('team.fields.email')}
                                    error={errors.email}
                                >
                                    <Input
                                        id="email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </FormField>
                            </>
                        )}

                        <FormField
                            id="role"
                            label={t('team.fields.role')}
                            error={errors.role}
                        >
                            <NativeSelect
                                id="role"
                                value={form.data.role}
                                onChange={(e) =>
                                    form.setData('role', e.target.value)
                                }
                            >
                                {roles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>

                        {brands.length > 0 && (
                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    {t('team.fields.brands')}
                                </legend>
                                <p className="text-xs text-muted-foreground">
                                    {t('team.brands_hint')}
                                </p>
                                {brands.map((brand) => (
                                    <label
                                        key={brand.id}
                                        className="flex min-h-9 items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={form.data.brand_ids.includes(
                                                brand.id,
                                            )}
                                            onCheckedChange={(c) =>
                                                toggleBrand(
                                                    brand.id,
                                                    c === true,
                                                )
                                            }
                                        />
                                        {brand.name}
                                    </label>
                                ))}
                            </fieldset>
                        )}

                        {editing && (
                            <label className="flex min-h-9 items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.is_active}
                                    onCheckedChange={(c) =>
                                        form.setData('is_active', c === true)
                                    }
                                />
                                {t('team.fields.is_active')}
                            </label>
                        )}

                        <Button type="submit" disabled={form.processing}>
                            {editing
                                ? t('common.save')
                                : t('team.send_invitation')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

TeamIndex.layout = {
    breadcrumbs: [{ title: 'team.title', href: index() }],
};
