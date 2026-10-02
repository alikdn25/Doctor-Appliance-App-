import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useTrans } from '@/lib/i18n';
import { edit, update } from '@/routes/company/google-profiles';
import type { Option } from '@/types';

type Row = {
    key: number;
    id: number | null;
    label: string;
    review_url: string;
    brand_id: string;
};

let rowKey = 0;

/**
 * Company → Google reviews: profiles with their review links, edited as one list.
 */
export default function GoogleProfiles({
    profiles,
    brands,
}: {
    profiles: {
        id: number;
        label: string;
        review_url: string;
        brand_id: number | null;
    }[];
    brands: Option[];
}) {
    const t = useTrans();
    const form = useForm<{ profiles: Row[] }>({
        profiles: profiles.map((p) => ({
            key: ++rowKey,
            id: p.id,
            label: p.label,
            review_url: p.review_url,
            brand_id: p.brand_id ? String(p.brand_id) : '',
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const rows = form.data.profiles;

    const setRow = (i: number, patch: Partial<Row>) =>
        form.setData(
            'profiles',
            rows.map((r, j) => (j === i ? { ...r, ...patch } : r)),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            profiles: data.profiles.map((r) => ({
                id: r.id,
                label: r.label,
                review_url: r.review_url,
                brand_id: r.brand_id || null,
            })),
        }));
        form.put(update().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('reviews.title')} />

            <form onSubmit={submit} className="max-w-2xl space-y-4 p-4">
                <PageHeader
                    title={t('reviews.title')}
                    description={t('reviews.description')}
                />
                <p className="text-sm text-muted-foreground">
                    {t('reviews.rules')}
                </p>

                {rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('reviews.empty')}
                    </p>
                )}

                <ul className="space-y-3">
                    {rows.map((row, i) => (
                        <li
                            key={row.key}
                            className="space-y-2 rounded-lg border p-3"
                        >
                            <div className="flex gap-2">
                                <div className="flex-1">
                                    <Input
                                        aria-label={t('reviews.fields.label')}
                                        placeholder={t('reviews.fields.label')}
                                        value={row.label}
                                        onChange={(e) =>
                                            setRow(i, { label: e.target.value })
                                        }
                                    />
                                    <InputError
                                        message={errors[`profiles.${i}.label`]}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-10"
                                    aria-label={t('reviews.remove')}
                                    onClick={() =>
                                        form.setData(
                                            'profiles',
                                            rows.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                            <Input
                                type="url"
                                inputMode="url"
                                aria-label={t('reviews.fields.review_url')}
                                placeholder="https://g.page/r/…/review"
                                value={row.review_url}
                                onChange={(e) =>
                                    setRow(i, { review_url: e.target.value })
                                }
                            />
                            <InputError
                                message={errors[`profiles.${i}.review_url`]}
                            />
                            {brands.length > 1 && (
                                <NativeSelect
                                    aria-label={t('reviews.fields.brand')}
                                    value={row.brand_id}
                                    onChange={(e) =>
                                        setRow(i, { brand_id: e.target.value })
                                    }
                                >
                                    <option value="">
                                        {t('reviews.any_brand')}
                                    </option>
                                    {brands.map((b) => (
                                        <option key={b.value} value={b.value}>
                                            {b.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                        </li>
                    ))}
                </ul>
                <p className="text-xs text-muted-foreground">
                    {t('reviews.link_hint')}
                </p>

                <Button
                    type="button"
                    variant="outline"
                    className="h-11 w-full"
                    onClick={() =>
                        form.setData('profiles', [
                            ...rows,
                            {
                                key: ++rowKey,
                                id: null,
                                label: '',
                                review_url: '',
                                brand_id: '',
                            },
                        ])
                    }
                >
                    <Plus /> {t('reviews.add')}
                </Button>

                <Button
                    type="submit"
                    className="w-full sm:w-auto"
                    disabled={form.processing}
                >
                    {t('common.save')}
                </Button>
            </form>
        </>
    );
}

GoogleProfiles.layout = {
    breadcrumbs: [{ title: 'reviews.title', href: edit() }],
};
