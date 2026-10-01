import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';
import { useTrans } from '@/lib/i18n';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const t = useTrans();

    return (
        <AuthLayoutTemplate
            title={title ? t(title) : ''}
            description={description ? t(description) : ''}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
