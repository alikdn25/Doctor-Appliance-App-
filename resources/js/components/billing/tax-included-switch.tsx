import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * "Price includes tax" (owner's task of November 22): a small switch under "+ Add line", off on every new screen.
 * Off: the ticked taxes are added on top of each line's amount. On: each amount already includes its ticked taxes and
 * they are worked back out of it.
 */
export function TaxIncludedSwitch({
    checked,
    onChange,
    testId = 'price-includes-tax',
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    testId?: string;
}) {
    const t = useTrans();

    return (
        <div className="grid gap-1">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                data-test={testId}
                className="da-press flex min-h-11 items-center gap-3 self-start text-left text-sm font-medium"
            >
                <span
                    aria-hidden="true"
                    className={cn(
                        'relative h-6 w-10 shrink-0 rounded-full transition-colors',
                        checked ? 'bg-[#1e8cfd]' : 'da-track',
                    )}
                >
                    <span
                        className={cn(
                            'da-raised absolute top-0.5 size-5 rounded-full bg-white transition-all',
                            checked ? 'left-[18px]' : 'left-0.5',
                        )}
                    />
                </span>
                {t('billing.price_includes_tax')}
            </button>
            {checked && (
                <p
                    className="text-xs text-muted-foreground"
                    data-test={`${testId}-hint`}
                >
                    {t('billing.taxes_inside')}
                </p>
            )}
        </div>
    );
}
