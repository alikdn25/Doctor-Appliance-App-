import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { BreadcrumbsContext } from '@/components/back-button';
import { ScreenHeaderSlot } from '@/components/screen-header';
import { UnfinishedJobsBar } from '@/components/unfinished-jobs-bar';
import { UploadStatusBar } from '@/components/upload-status-bar';
import { useDetectTimezone } from '@/hooks/use-detect-timezone';
import type { AppLayoutProps } from '@/types';
import { useState } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    useDetectTimezone();
    const [slot, setSlot] = useState<HTMLDivElement | null>(null);

    return (
        <BreadcrumbsContext.Provider value={breadcrumbs}>
            <ScreenHeaderSlot.Provider value={slot}>
                <AppShell variant="sidebar">
                    <AppSidebar />
                    <AppContent
                        variant="sidebar"
                        className="min-w-0 overflow-x-clip"
                    >
                        <ImpersonationBanner />
                        <UnfinishedJobsBar />
                        <AppSidebarHeader
                            breadcrumbs={breadcrumbs}
                            slotRef={setSlot}
                        />
                        {/* Light sheet with rounded top corners under the dark header. */}
                        <div className="relative z-10 -mt-4 flex min-w-0 flex-1 flex-col rounded-t-[20px] bg-background">
                            <UploadStatusBar />
                            {children}
                            {/* Room for the fixed bottom bar on a phone. */}
                            <div
                                className="h-20 md:hidden"
                                aria-hidden="true"
                            />
                        </div>
                        <AppBottomNav />
                    </AppContent>
                </AppShell>
            </ScreenHeaderSlot.Provider>
        </BreadcrumbsContext.Provider>
    );
}
