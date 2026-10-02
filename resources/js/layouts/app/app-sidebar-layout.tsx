import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ImpersonationBanner } from '@/components/impersonation-banner';
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
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <UploadStatusBar />
                {children}
            </AppContent>
        </AppShell>
    );
}
