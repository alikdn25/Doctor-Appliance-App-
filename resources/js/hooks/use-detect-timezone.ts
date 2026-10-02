import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { detect } from '@/routes/company/timezone';

/**
 * A new company without a time zone takes the Owner's browser time zone on their first visit.
 * Skipped while a super-admin impersonates (their browser is not the Owner's).
 */
export function useDetectTimezone(): void {
    const { auth, impersonation } = usePage().props;
    const sent = useRef(false);
    const pending = auth.company?.timezone_pending === true && !impersonation;

    useEffect(() => {
        if (!pending || sent.current) {
            return;
        }

        const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (!timezone) {
            return;
        }

        sent.current = true;
        router.put(
            detect().url,
            { timezone },
            { preserveScroll: true, preserveState: true },
        );
    }, [pending]);
}
