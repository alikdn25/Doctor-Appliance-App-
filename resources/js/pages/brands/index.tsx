import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus } from 'lucide-react';
import { headerButtonClass, ScreenHeader } from '@/components/screen-header';
import { Badge } from '@/components/ui/badge';
import { useTrans } from '@/lib/i18n';
import { usePhone } from '@/lib/phone';
import { create, edit, index } from '@/routes/brands';

type BrandRow = {
    id: number;
    name: string;
    logo_url: string | null;
    primary_color: string | null;
    phone: string | null;
    email: string | null;
    is_active: boolean;
    city: string | null;
};

export default function BrandsIndex({
    brands,
    canCreate,
}: {
    brands: BrandRow[];
    canCreate: boolean;
}) {
    const t = useTrans();
    const phoneText = usePhone();

    return (
        <>
            <Head title={t('brands.title')} />

            <ScreenHeader
                title={t('brands.title')}
                actions={
                    canCreate && (
                        <Link
                            href={create()}
                            className={headerButtonClass}
                            aria-label={t('brands.add')}
                            title={t('brands.add')}
                        >
                            <Plus className="size-5" />
                        </Link>
                    )
                }
            />

            <div className="mx-auto w-full max-w-3xl space-y-3 p-4 sm:p-6">
                <p className="text-sm text-muted-foreground">
                    {t('brands.description')}
                </p>

                {brands.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {t('brands.empty')}
                    </p>
                ) : (
                    <ul className="da-card divide-y overflow-hidden">
                        {brands.map((brand) => (
                            <li key={brand.id}>
                                <Link
                                    href={edit(brand.id)}
                                    className="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-muted/50"
                                >
                                    {brand.logo_url ? (
                                        <img
                                            src={brand.logo_url}
                                            loading="lazy"
                                            decoding="async"
                                            alt=""
                                            className="size-11 rounded-xl border bg-white object-contain p-0.5"
                                        />
                                    ) : (
                                        <div
                                            className="flex size-11 items-center justify-center rounded-xl text-base font-bold text-white"
                                            style={{
                                                backgroundColor:
                                                    brand.primary_color ??
                                                    '#64748b',
                                            }}
                                            aria-hidden="true"
                                        >
                                            {brand.name
                                                .split(' ')
                                                .map((part) => part[0])
                                                .slice(0, 2)
                                                .join('')
                                                .toUpperCase()}
                                        </div>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            {brand.primary_color && (
                                                <span
                                                    className="size-3 shrink-0 rounded-full border border-black/10"
                                                    style={{
                                                        backgroundColor:
                                                            brand.primary_color,
                                                    }}
                                                    aria-hidden="true"
                                                />
                                            )}
                                            <span className="truncate font-semibold">
                                                {brand.name}
                                            </span>
                                            {!brand.is_active && (
                                                <Badge variant="secondary">
                                                    {t('common.inactive')}
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {[
                                                brand.city,
                                                phoneText(brand.phone),
                                                brand.email,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </div>
                                    <ChevronRight className="size-4 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

BrandsIndex.layout = {
    breadcrumbs: [{ title: 'brands.title', href: index() }],
};
