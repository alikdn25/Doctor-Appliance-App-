import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Plus, Tags } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

            <div className="p-4">
                <PageHeader
                    title={t('brands.title')}
                    description={t('brands.description')}
                    actions={
                        canCreate && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus /> {t('brands.add')}
                                </Link>
                            </Button>
                        )
                    }
                />

                {brands.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {t('brands.empty')}
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {brands.map((brand) => (
                            <li key={brand.id}>
                                <Link
                                    href={edit(brand.id)}
                                    className="flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-muted/50"
                                >
                                    {brand.logo_url ? (
                                        <img
                                            src={brand.logo_url}
                                            alt=""
                                            className="size-10 rounded-md object-contain"
                                        />
                                    ) : (
                                        <div
                                            className="flex size-10 items-center justify-center rounded-md text-white"
                                            style={{
                                                backgroundColor:
                                                    brand.primary_color ??
                                                    '#64748b',
                                            }}
                                        >
                                            <Tags className="size-5" />
                                        </div>
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="truncate font-medium">
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
