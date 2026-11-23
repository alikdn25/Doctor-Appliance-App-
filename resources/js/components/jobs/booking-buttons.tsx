import { Link, usePage } from '@inertiajs/react';
import { PenLine, Sparkles } from 'lucide-react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { create as bookCustomer, quickBooking } from '@/routes/jobs';

/**
 * The "+" menu only (never on a screen itself): two buttons side by side. Quick booking (violet with a
 * soft glow and ✨: one text field, the AI fills the form) and Book customer (white, a pen: the empty form by hand).
 */
export function BookingButtons({
    date,
    onPick,
    className,
}: {
    /** The day shown (calendar): Book customer opens on it. */
    date?: string | null;
    /** Called before the visit (e.g. to close the "+" menu). */
    onPick?: () => void;
    className?: string;
}) {
    const t = useTrans();
    const { can } = usePage().props.auth;
    const quick = can.quickBooking === true;
    const manual = can.createJobs === true;

    if (!quick && !manual) return null;

    const button =
        'da-press inline-flex h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-2xl px-3 text-[15px] font-semibold whitespace-nowrap [&_svg]:size-5 [&_svg]:shrink-0';

    return (
        <div
            className={cn('flex w-full gap-2', className)}
            data-test="booking-buttons"
        >
            {quick && (
                <Link
                    href={quickBooking()}
                    onClick={onPick}
                    className={cn(button, 'da-quick-booking')}
                    data-test="create-quick-booking"
                >
                    <Sparkles /> {t('jobs.quick_booking.button')}
                </Link>
            )}
            {manual && (
                <Link
                    href={bookCustomer({
                        query: date ? { book: 1, date } : { book: 1 },
                    })}
                    onClick={onPick}
                    className={cn(button, 'da-raised')}
                    data-test="create-job"
                >
                    <PenLine /> {t('nav.book_customer')}
                </Link>
            )}
        </div>
    );
}
