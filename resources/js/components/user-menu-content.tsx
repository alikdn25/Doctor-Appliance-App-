import { Link, router, usePage } from '@inertiajs/react';
import { Bell, LogOut, Settings } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTrans } from '@/lib/i18n';
import { forgetPushOnThisDevice } from '@/lib/push';
import { logout } from '@/routes';
import { index as notifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const t = useTrans();
    const unread = usePage().props.auth.unreadNotifications ?? 0;

    const handleLogout = () => {
        cleanup();
        void forgetPushOnThisDevice();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        {t('nav.settings')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="flex w-full cursor-pointer items-center"
                        href={notifications()}
                        onClick={cleanup}
                        data-test="notifications-link"
                    >
                        <Bell className="mr-2" />
                        <span className="flex-1">{t('nav.notifications')}</span>
                        {unread > 0 && (
                            <span className="rounded-full bg-primary px-2 text-xs font-semibold text-primary-foreground">
                                {unread}
                            </span>
                        )}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    {t('nav.log_out')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
