import type { Auth, Impersonation } from '@/types/auth';
import type { JobBacklogSummary } from '@/types/job-backlog';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            impersonation: Impersonation;
            unfinishedJobs: JobBacklogSummary | null;
            unreadMessages: number | null;
            locale: string;
            translations: Record<string, unknown>;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
