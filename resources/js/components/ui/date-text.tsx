import { useCompanyTime } from '@/lib/datetime';

/**
 * A date or time in the company's time zone and regional format — the one date formatter of the app; ISO dates are
 * never shown to people. `value` is an ISO timestamp, or YYYY-MM-DD for `format="date-only"`.
 */
export function DateText({
    value,
    format = 'date',
    className,
}: {
    value: string | null | undefined;
    format?: 'date' | 'date-only' | 'day' | 'full-day' | 'time' | 'date-time';
    className?: string;
}) {
    const time = useCompanyTime();

    if (!value) {
        return null;
    }

    const text = {
        date: () => time.date(value),
        'date-only': () => time.dateOnly(value),
        day: () => time.day(value),
        'full-day': () => time.fullDay(value),
        time: () => time.time(value),
        'date-time': () => time.dateTime(value),
    }[format]();

    return (
        <time dateTime={value} className={className}>
            {text}
        </time>
    );
}
