import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Building2, ChevronRight, LogOut, UserRound } from 'lucide-react';
import { useState } from 'react';
import { ListRow } from '@/components/ui/list-row';
import { SearchField } from '@/components/ui/search-field';
import { SegmentedControl } from '@/components/ui/segmented-control';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { navPath, useAppNavigation } from '@/hooks/use-app-navigation';
import { useTrans } from '@/lib/i18n';
import { forgetPushOnThisDevice } from '@/lib/push';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { switchMethod } from '@/routes/companies';
import { update as updateMenuMode } from '@/routes/menu-mode';
import { index as notifications } from '@/routes/notifications';
import { edit as profile } from '@/routes/profile';

const tile =
    'da-soft inline-flex size-10 items-center justify-center rounded-[12px] [&_svg]:size-5';

/**
 * More (UX-REDESIGN §5.4, §22): "My menu: Field | Office" first, a search over the menu, the groups (open for the
 * Owner), then the person: profile, notifications, company switch and sign-out. Items already in the bottom bar
 * are not repeated.
 */
export function MoreSheet({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { auth } = usePage().props;
    const t = useTrans();
    const { groups, bottom, inboxInHeader } = useAppNavigation();
    const [query, setQuery] = useState('');
    const isOwner = auth.role?.value === 'owner';
    const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
    const inBottom = new Set(bottom.map((item) => navPath(item.href)));
    const close = () => onOpenChange(false);
    const needle = query.trim().toLowerCase();

    const shown = groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    !inBottom.has(navPath(item.href)) &&
                    item.key !== 'book' &&
                    !(inboxInHeader && item.key === 'messages') &&
                    (needle === '' ||
                        t(item.title).toLowerCase().includes(needle)),
            ),
        }))
        .filter((group) => group.items.length > 0);

    const isOpen = (key: string) =>
        needle !== '' || (openGroups[key] ?? (key === 'main' || isOwner));

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="gap-3 px-4 pt-4"
                data-test="more-sheet"
            >
                <SheetHeader className="p-0 pr-12">
                    <SheetTitle>{t('nav.more_title')}</SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('nav.search_menu')}
                    </SheetDescription>
                </SheetHeader>

                {auth.user?.can_choose_menu && (
                    <div className="space-y-1.5" data-test="menu-mode">
                        <p className="text-sm font-semibold">
                            {t('nav.my_menu')}
                        </p>
                        <SegmentedControl
                            label={t('nav.my_menu')}
                            className="flex w-full"
                            value={auth.user.menu_mode}
                            onChange={(mode) =>
                                router.put(
                                    updateMenuMode().url,
                                    { mode },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                )
                            }
                            options={[
                                { value: 'field', label: t('nav.menu_field') },
                                {
                                    value: 'office',
                                    label: t('nav.menu_office'),
                                },
                            ]}
                        />
                        <p className="text-xs text-muted-foreground">
                            {t(
                                auth.user.menu_mode === 'office'
                                    ? 'nav.menu_office_hint'
                                    : 'nav.menu_field_hint',
                            )}
                        </p>
                    </div>
                )}

                <SearchField
                    value={query}
                    onSearch={setQuery}
                    delay={0}
                    placeholder={t('nav.search_menu')}
                />

                <nav aria-label={t('nav.more_title')} className="space-y-2">
                    {shown.length === 0 && (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            {t('nav.no_matches')}
                        </p>
                    )}
                    {shown.map((group) => (
                        <section key={group.key}>
                            <button
                                type="button"
                                className="flex min-h-11 w-full items-center gap-2 px-1 text-left text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                                aria-expanded={isOpen(group.key)}
                                onClick={() =>
                                    setOpenGroups((g) => ({
                                        ...g,
                                        [group.key]: !isOpen(group.key),
                                    }))
                                }
                            >
                                <span className="flex-1">{t(group.label)}</span>
                                <ChevronRight
                                    aria-hidden="true"
                                    className={cn(
                                        'size-4 transition-transform',
                                        isOpen(group.key) && 'rotate-90',
                                    )}
                                />
                            </button>
                            {isOpen(group.key) && (
                                <div className="da-card divide-y overflow-hidden">
                                    {group.items.map((item) => (
                                        <div
                                            key={item.key}
                                            onClickCapture={close}
                                        >
                                            <ListRow
                                                href={item.href}
                                                leading={
                                                    <span className={tile}>
                                                        <item.icon />
                                                    </span>
                                                }
                                                title={t(item.title)}
                                                trailing={
                                                    item.badge ? (
                                                        <span className="rounded-full bg-danger px-2 text-xs leading-5 font-semibold text-white">
                                                            {item.badge}
                                                        </span>
                                                    ) : undefined
                                                }
                                            />
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    ))}
                </nav>

                <div
                    className="da-card divide-y overflow-hidden"
                    data-test="more-person"
                >
                    <div onClickCapture={close}>
                        <ListRow
                            href={profile()}
                            leading={
                                <span className={tile}>
                                    <UserRound />
                                </span>
                            }
                            title={auth.user?.name}
                            subtitle={[auth.role?.label, auth.company?.name]
                                .filter(Boolean)
                                .join(' · ')}
                            chevron
                        />
                    </div>
                    <div onClickCapture={close}>
                        <ListRow
                            href={notifications()}
                            leading={
                                <span className={tile}>
                                    <Bell />
                                </span>
                            }
                            title={t('nav.notifications')}
                            trailing={
                                auth.unreadNotifications ? (
                                    <span className="rounded-full bg-primary px-2 text-xs leading-5 font-semibold text-primary-foreground">
                                        {auth.unreadNotifications}
                                    </span>
                                ) : undefined
                            }
                        />
                    </div>
                    {auth.companies.length > 1 &&
                        auth.companies
                            .filter(
                                (company) => company.id !== auth.company?.id,
                            )
                            .map((company) => (
                                <ListRow
                                    key={company.id}
                                    onClick={() => {
                                        close();
                                        router.post(
                                            switchMethod(company.id).url,
                                        );
                                    }}
                                    leading={
                                        <span className={tile}>
                                            <Building2 />
                                        </span>
                                    }
                                    title={company.name}
                                    subtitle={t('nav.switch_company')}
                                />
                            ))}
                    <Link
                        href={logout()}
                        method="post"
                        as="button"
                        onClick={() => {
                            close();
                            void forgetPushOnThisDevice();
                            router.flushAll();
                        }}
                        className="flex min-h-14 w-full items-center gap-3 px-3 text-left text-base font-semibold text-danger"
                        data-test="more-logout"
                    >
                        <span className={cn(tile, 'text-danger')}>
                            <LogOut />
                        </span>
                        {t('nav.log_out')}
                    </Link>
                </div>
            </SheetContent>
        </Sheet>
    );
}
