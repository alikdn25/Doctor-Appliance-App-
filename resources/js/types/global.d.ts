import type { Auth, Impersonation } from '@/types/auth';

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
            locale: string;
            translations: Record<string, unknown>;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
