import { PaidInFullFlow } from '@/components/billing/paid-in-full';
import { KeyboardShortcuts } from '@/components/keyboard-shortcuts';
import { AfterPartPrompts } from '@/components/parts/after-part-prompts';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
            <PaidInFullFlow />
            <AfterPartPrompts />
            <KeyboardShortcuts />
        </AppLayoutTemplate>
    );
}
