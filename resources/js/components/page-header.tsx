import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ScreenHeader, useHasScreenHeader } from '@/components/screen-header';

/**
 * Title of a screen. Inside the app it is shown in the dark header, like on My jobs and Jobs, so every
 * section has the same header with its name; the screen's buttons stay at the top of the page.
 */
export function PageHeader({
    title,
    description,
    actions,
    back,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    /** Shows a back arrow in the app header instead of the menu button. */
    back?: NonNullable<InertiaLinkProps['href']>;
}) {
    const inApp = useHasScreenHeader();

    if (inApp) {
        return (
            <>
                <ScreenHeader
                    title={title}
                    subtitle={description}
                    back={back}
                    own={false}
                />
                {actions && (
                    <div className="mb-4 flex flex-wrap justify-end gap-2">
                        {actions}
                    </div>
                )}
            </>
        );
    }

    return (
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && <div className="flex gap-2">{actions}</div>}
        </div>
    );
}
