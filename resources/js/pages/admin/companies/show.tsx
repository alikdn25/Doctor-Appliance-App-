import { Head, router, useForm } from '@inertiajs/react';
import { UserCheck } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { impersonate, index, update } from '@/routes/admin/companies';
import type { Option } from '@/types';

type Props = {
    company: {
        id: number;
        name: string;
        slug: string;
        status: string;
        plan: string | null;
        subscription_status: string | null;
        timezone: string;
        currency: string;
        brands_count: number;
        created_at: string;
    };
    members: {
        user_id: number;
        name: string;
        email: string;
        role: string;
        is_active: boolean;
        last_login_at: string | null;
    }[];
    impersonations: {
        id: number;
        super_admin: string;
        user: string;
        reason: string | null;
        started_at: string;
        ended_at: string | null;
    }[];
    auditLogs: {
        id: number;
        action: string;
        user: string | null;
        impersonator: string | null;
        created_at: string;
    }[];
    statuses: Option[];
    subscriptionStatuses: Option[];
};

export default function AdminCompanyShow({
    company,
    members,
    impersonations,
    auditLogs,
    statuses,
    subscriptionStatuses,
}: Props) {
    const t = useTrans();
    const form = useForm({
        name: company.name,
        status: company.status,
        plan: company.plan ?? '',
        subscription_status: company.subscription_status ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(update(company.id).url, { preserveScroll: true });
    };

    const startImpersonation = (userId: number, name: string) => {
        const reason = prompt(t('admin.impersonate_reason', { name }));

        if (reason === null) {
            return;
        }

        router.post(impersonate([company.id, userId]).url, { reason });
    };

    return (
        <>
            <Head title={company.name} />

            <div className="flex max-w-3xl flex-col gap-8 p-4">
                <PageHeader
                    title={company.name}
                    description={`${company.slug} · ${company.timezone} · ${company.currency} · ${t('admin.companies.brands_count', { count: company.brands_count })}`}
                />

                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="name"
                        label={t('company.fields.name')}
                        error={form.errors.name}
                    >
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="status"
                        label={t('admin.fields.status')}
                        error={form.errors.status}
                    >
                        <NativeSelect
                            id="status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            {statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
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
                            <option value="">—</option>
                            {subscriptionStatuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        className="sm:w-fit"
                    >
                        {t('common.save')}
                    </Button>
                </form>

                <section>
                    <h2 className="mb-3 text-base font-medium">
                        {t('admin.members')}
                    </h2>
                    <ul className="divide-y rounded-lg border">
                        {members.map((m) => (
                            <li
                                key={m.user_id}
                                className="flex flex-wrap items-center gap-3 px-4 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {m.name}
                                        </span>
                                        <Badge variant="outline">
                                            {m.role}
                                        </Badge>
                                        {!m.is_active && (
                                            <Badge variant="secondary">
                                                {t('common.inactive')}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {m.email} ·{' '}
                                        {m.last_login_at
                                            ? t('admin.last_login', {
                                                  date: m.last_login_at,
                                              })
                                            : t('admin.never_logged_in')}
                                    </div>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        startImpersonation(m.user_id, m.name)
                                    }
                                >
                                    <UserCheck /> {t('admin.impersonate')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>

                <section>
                    <h2 className="mb-3 text-base font-medium">
                        {t('admin.impersonation_log')}
                    </h2>
                    {impersonations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.none')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {impersonations.map((log) => (
                                <li key={log.id} className="px-4 py-2">
                                    <div>
                                        {t('admin.impersonation_entry', {
                                            admin: log.super_admin,
                                            user: log.user,
                                        })}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {log.started_at} →{' '}
                                        {log.ended_at ?? t('admin.active')}
                                        {log.reason && ` · ${log.reason}`}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section>
                    <h2 className="mb-3 text-base font-medium">
                        {t('admin.audit_log')}
                    </h2>
                    {auditLogs.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.none')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {auditLogs.map((log) => (
                                <li
                                    key={log.id}
                                    className="flex flex-wrap gap-x-3 px-4 py-2"
                                >
                                    <code className="text-xs">
                                        {log.action}
                                    </code>
                                    <span className="text-xs text-muted-foreground">
                                        {log.user ?? '—'}
                                        {log.impersonator &&
                                            ` (${t('admin.via', { name: log.impersonator })})`}
                                        {` · ${log.created_at}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

AdminCompanyShow.layout = {
    breadcrumbs: [{ title: 'admin.companies.title', href: index() }],
};
