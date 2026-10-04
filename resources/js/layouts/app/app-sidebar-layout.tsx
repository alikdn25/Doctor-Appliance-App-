import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { MobileTabBar } from '@/components/mobile-tab-bar';
import { UnfinishedJobsBar } from '@/components/unfinished-jobs-bar';
import { UploadStatusBar } from '@/components/upload-status-bar';
import { useDetectTimezone } from '@/hooks/use-detect-timezone';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    useDetectTimezone();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <ImpersonationBanner />
                <UnfinishedJobsBar />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/* Light sheet with rounded top corners under the dark header. */}
                <div className="relative z-10 -mt-4 flex min-w-0 flex-1 flex-col rounded-t-[20px] bg-background">
                    <UploadStatusBar />
                    {children}
                </div>
                <MobileTabBar />
            </AppContent>
        </AppShell>
    );
}
