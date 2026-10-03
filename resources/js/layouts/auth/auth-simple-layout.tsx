import { Link, usePage } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage<{ name: string }>().props;
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-muted/30 px-4 py-8 sm:p-10">
            <div className="w-full max-w-lg rounded-3xl border bg-card p-6 shadow-sm sm:p-8">
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex size-12 items-center justify-center rounded-2xl bg-primary text-primary-foreground">
                                <Wrench className="size-6" aria-hidden="true" />
                            </div>
                            <span className="text-lg font-semibold">{name}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
