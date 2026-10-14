import { useMoney } from '@/components/billing/money';
import { cn } from '@/lib/utils';

/**
 * An amount in minor units, formatted in its currency and the company's regional format, with tabular figures.
 * `size="lg"` is the 28/700 used only for big totals.
 */
export function MoneyText({
    minor,
    currency,
    size = 'md',
    className,
}: {
    minor: number;
    currency?: string;
    size?: 'md' | 'lg';
    className?: string;
}) {
    const money = useMoney(currency);

    return (
        <span
            className={cn(
                'tabular-nums',
                size === 'lg' && 'text-[28px] leading-tight font-bold',
                minor < 0 && 'text-danger',
                className,
            )}
        >
            {money(minor)}
        </span>
    );
}
