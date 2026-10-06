import { parseNumber } from './money';
import { useTrans } from '@/lib/i18n';

/**
 * Shows how a typed number was read, so "150,00" never silently becomes 15 000: "= $150.00" when the text has a
 * comma or other marks, a warning for "1,500" (thousands or cents?), and an error for text that is not a number.
 * Silent for plain input like "150" or "150.5".
 */
export function NumberHint({
    text,
    format,
}: {
    text: string;
    /** Formats the normalized number, e.g. as money in the document currency. */
    format: (value: number) => string;
}) {
    const t = useTrans();
    const { normalized, ambiguous } = parseNumber(text);

    if (normalized === null) {
        return (
            <p className="mt-1 text-xs text-destructive" role="alert">
                {t('billing.number.invalid', { example: '150.50' })}
            </p>
        );
    }

    if (normalized === '' || /^-?\d+(\.\d+)?$/.test(text.trim())) {
        return null;
    }

    const value = format(Number(normalized));

    return (
        <p
            className={
                ambiguous
                    ? 'mt-1 text-xs text-amber-700 dark:text-amber-400'
                    : 'mt-1 text-xs text-muted-foreground'
            }
            role="status"
        >
            {ambiguous
                ? t('billing.number.ambiguous', {
                      value,
                      example: text.trim().replace(/,(\d{2})\d$/, '.$1'),
                  })
                : t('billing.number.reads_as', { value })}
        </p>
    );
}
