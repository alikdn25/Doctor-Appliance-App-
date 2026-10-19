import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { KindBadge } from './line-editor';
import type { LineKind } from './line-editor';
import type { JobEstimate, ServiceOption } from './types';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTrans } from '@/lib/i18n';

/**
 * "Add item": Labour · Part · Material for a line typed by hand right under the title, "From estimate EST-1" (all
 * lines of an estimate of the job in one tap), then the price book with a search that filters as you type (the
 * item's type comes from the price book).
 */
export function AddItemSheet({
    open,
    services,
    money,
    onOpenChange,
    onPick,
    onCustom,
    estimates = [],
    onEstimate,
}: {
    open: boolean;
    services: ServiceOption[];
    /** Estimates of the job whose lines can be added at once (invoices). */
    estimates?: JobEstimate[];
    onEstimate?: (estimate: JobEstimate) => void;
    money: (minor: number, currency?: string) => string;
    onOpenChange: (open: boolean) => void;
    onPick: (service: ServiceOption) => void;
    onCustom: (kind: LineKind) => void;
}) {
    const t = useTrans();
    const [query, setQuery] = useState('');
    const shown = useMemo(() => {
        const words = query.toLowerCase().split(/\s+/).filter(Boolean);

        return services.filter((service) => {
            const text = [
                service.name,
                service.description,
                service.category,
                service.part_number,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return words.every((word) => text.includes(word));
        });
    }, [services, query]);

    const close = (next: boolean) => {
        if (!next) {
            setQuery('');
        }

        onOpenChange(next);
    };

    return (
        <Sheet open={open} onOpenChange={close}>
            <SheetContent
                side="bottom"
                className="mx-auto flex max-h-[88dvh] max-w-3xl flex-col gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle>{t('billing.add_sheet.title')}</SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('billing.add_sheet.hint')}
                    </SheetDescription>
                </SheetHeader>

                {/* Labour · Part · Material on top: a line typed by hand in one tap (owner's fixes, October 19). */}
                <div className="space-y-1" data-test="add-custom">
                    <p className="text-xs text-muted-foreground">
                        {t('billing.add_sheet.custom')}
                    </p>
                    <div className="grid grid-cols-3 gap-2">
                        {(['service', 'part', 'material'] as const).map(
                            (kind) => (
                                <button
                                    key={kind}
                                    type="button"
                                    className="da-raised da-press flex h-12 items-center justify-center gap-2 rounded-2xl px-2 text-sm font-semibold"
                                    onClick={() => {
                                        onCustom(kind);
                                        close(false);
                                    }}
                                >
                                    <KindBadge
                                        kind={kind}
                                        className="size-6 text-xs"
                                        decorative
                                    />
                                    {t(`billing.kinds.${kind}`)}
                                </button>
                            ),
                        )}
                    </div>
                </div>

                {estimates.length > 0 && onEstimate && (
                    <div
                        className="flex flex-wrap gap-2"
                        data-test="from-estimate"
                    >
                        {estimates.map((estimate) => (
                            <button
                                key={estimate.id}
                                type="button"
                                className="da-chip da-press inline-flex h-10 items-center gap-1.5 rounded-full px-3.5 text-sm font-medium"
                                onClick={() => {
                                    onEstimate(estimate);
                                    close(false);
                                }}
                            >
                                {t('billing.add_sheet.from_estimate', {
                                    number: estimate.number,
                                    count: estimate.items.length,
                                })}
                            </button>
                        ))}
                    </div>
                )}

                {services.length > 0 && (
                    <>
                        <div className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                aria-label={t('billing.add_sheet.search')}
                                placeholder={t('billing.add_sheet.search')}
                                className="pl-9"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                        <ul
                            className="-mx-1 min-h-0 flex-1 space-y-1 overflow-y-auto px-1"
                            aria-label={t('billing.add_sheet.price_book')}
                        >
                            {shown.length === 0 && (
                                <li className="py-3 text-sm text-muted-foreground">
                                    {t('billing.add_sheet.nothing_found')}
                                </li>
                            )}
                            {shown.map((service) => (
                                <li key={service.id}>
                                    <button
                                        type="button"
                                        className="flex min-h-14 w-full items-center gap-3 rounded-xl px-2 py-2 text-left hover:bg-muted/60 active:bg-muted"
                                        onClick={() => {
                                            onPick(service);
                                            close(false);
                                        }}
                                    >
                                        <KindBadge
                                            kind={service.kind}
                                            decorative
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {service.name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {[
                                                    t(
                                                        `billing.kinds.${service.kind}`,
                                                    ),
                                                    service.category,
                                                    service.part_number,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {service.unit_price !== null
                                                ? money(
                                                      service.unit_price,
                                                      service.currency,
                                                  )
                                                : '—'}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
