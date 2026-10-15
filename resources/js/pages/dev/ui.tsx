import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    ClipboardList,
    Inbox,
    MessageSquare,
    MoreHorizontal,
    Navigation,
    Pencil,
    Phone,
    Play,
    Plus,
    Search,
    Send,
    Sun,
    Trash2,
    Car,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AddressInput } from '@/components/ui/address-input';
import { PageHeader } from '@/components/page-header';
import { AppBar } from '@/components/ui/app-bar';
import { BottomNav } from '@/components/ui/bottom-nav';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DateChips } from '@/components/ui/date-chips';
import { DateText } from '@/components/ui/date-text';
import { EditableList } from '@/components/ui/editable-list';
import { EmptyState } from '@/components/ui/empty-state';
import { Fab } from '@/components/ui/fab';
import { FilterChips } from '@/components/ui/filter-chips';
import { FormField } from '@/components/ui/form-field';
import { IconButton } from '@/components/ui/icon-button';
import { Input } from '@/components/ui/input';
import { ListRow } from '@/components/ui/list-row';
import { MoneyText } from '@/components/ui/money-text';
import { OverflowMenu } from '@/components/ui/overflow-menu';
import { PhoneInput } from '@/components/ui/phone-input';
import { SearchField } from '@/components/ui/search-field';
import { Section } from '@/components/ui/section';
import { SegmentedControl } from '@/components/ui/segmented-control';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge, OverdueFlag } from '@/components/ui/status-badge';
import { StickyActionBar } from '@/components/ui/sticky-action-bar';
import { TagInput } from '@/components/ui/tag-input';
import { TimeWindowChips } from '@/components/ui/time-window-chips';
import { useTrans } from '@/lib/i18n';
import { toast } from '@/lib/toast';

const JOB_STATUSES = [
    'new',
    'scheduled',
    'on_the_way',
    'in_progress',
    'waiting_for_parts',
    'waiting_for_customer',
    'on_hold',
    'completed',
    'invoiced',
    'paid',
    'cancelled',
];

const WINDOWS = [
    { start: '09:00', end: '11:00' },
    { start: '11:00', end: '13:00' },
    { start: '13:00', end: '15:00' },
    { start: '15:00', end: '17:00' },
];

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3" data-test="ui-kit-block">
            <h2 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {title}
            </h2>
            <div className="da-card space-y-4 p-4">{children}</div>
        </section>
    );
}

const row = 'flex flex-wrap items-center gap-2';

/**
 * Every UI kit component in every state, on one page (UX-REDESIGN §16.1 phase 2). Local development only.
 */
export default function UiKit() {
    const t = useTrans();
    const s = (key: string) => t(`dev.sample.${key}`);
    const today = new Date().toISOString().slice(0, 10);
    const [search, setSearch] = useState('');
    const [chips, setChips] = useState<string[]>(['overdue']);
    const [segment, setSegment] = useState<'day' | 'week' | 'map'>('day');
    const [day, setDay] = useState(today);
    const [windowStart, setWindowStart] = useState<string | null>('09:00');
    const [phone, setPhone] = useState('');
    const [address, setAddress] = useState('');
    const [tags, setTags] = useState<string[]>([s('tag_vip')]);
    const [windows, setWindows] = useState(WINDOWS.slice(0, 2));
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);

    return (
        <>
            <Head title={t('dev.title')} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4 pb-40">
                <PageHeader
                    title={t('dev.title')}
                    description={t('dev.subtitle')}
                    actions={
                        <OverflowMenu
                            variant="raised"
                            items={[
                                {
                                    key: 'edit',
                                    label: s('edit'),
                                    icon: <Pencil />,
                                    onSelect: () => undefined,
                                },
                                'separator',
                                {
                                    key: 'delete',
                                    label: s('delete_job'),
                                    icon: <Trash2 />,
                                    destructive: true,
                                    onSelect: () => setConfirmOpen(true),
                                },
                            ]}
                        />
                    }
                />

                <Block title={t('dev.sections.buttons')}>
                    <div className={row}>
                        <Button>
                            <Car /> {s('on_my_way')}
                        </Button>
                        <Button variant="outline">
                            <Play /> {s('start_job')}
                        </Button>
                        <Button variant="secondary">
                            <Send /> {s('send')}
                        </Button>
                        <Button variant="tertiary">
                            <Pencil /> {s('edit')}
                        </Button>
                        <Button variant="ghost">{s('add_discount')}</Button>
                        <Button variant="destructive">
                            <Trash2 /> {s('delete_job')}
                        </Button>
                    </div>
                    <h3 className="text-sm font-semibold">
                        {t('dev.sections.states')}
                    </h3>
                    <div className={row}>
                        <Button disabled>{s('book')}</Button>
                        <Button loading>{s('saving')}</Button>
                        <Button variant="destructiveSolid">
                            {s('void_invoice')}
                        </Button>
                        <Button size="sm">{s('take_payment')}</Button>
                        <Button size="lg">{s('take_payment')}</Button>
                    </div>
                </Block>

                <Block title={t('dev.sections.icon_buttons')}>
                    <div className={row}>
                        <IconButton label={s('navigate')}>
                            <Navigation />
                        </IconButton>
                        <IconButton label={s('call')}>
                            <Phone />
                        </IconButton>
                        <IconButton label={s('text')}>
                            <MessageSquare />
                        </IconButton>
                        <IconButton
                            label={s('messages')}
                            variant="plain"
                            count={2}
                        >
                            <Inbox />
                        </IconButton>
                        <IconButton label={s('book')} variant="primary">
                            <Plus />
                        </IconButton>
                        <div className="da-header flex gap-2 rounded-2xl p-2">
                            <IconButton label={s('search')} variant="header">
                                <Search />
                            </IconButton>
                            <IconButton
                                label={t('ui.more_actions')}
                                variant="header"
                            >
                                <MoreHorizontal />
                            </IconButton>
                        </div>
                    </div>
                </Block>

                <Block title={t('dev.sections.statuses')}>
                    <div className={row}>
                        {JOB_STATUSES.map((status) => (
                            <StatusBadge
                                key={status}
                                status={status}
                                label={t(`jobs.statuses.${status}`)}
                            />
                        ))}
                    </div>
                    <div className={row}>
                        <StatusBadge
                            status="waiting_for_parts"
                            label={t('jobs.statuses.waiting_for_parts')}
                        />
                        <OverdueFlag />
                    </div>
                    <h3 className="text-sm font-semibold">
                        {t('dev.sections.documents')}
                    </h3>
                    <div className={row}>
                        <StatusBadge
                            status="draft"
                            label={t('estimates.statuses.draft')}
                        />
                        <StatusBadge status="sent" label={s('sent')} />
                        <StatusBadge
                            status="approved"
                            label={t('estimates.statuses.approved')}
                        />
                        <StatusBadge
                            status="declined"
                            label={t('estimates.statuses.declined')}
                        />
                        <StatusBadge
                            status="revised"
                            label={t('estimates.statuses.revised')}
                        />
                        <StatusBadge status="unpaid" label={s('due')} />
                        <OverdueFlag label={s('overdue_days')} />
                        <StatusBadge
                            status="partially_paid"
                            label={t('invoices.statuses.partially_paid')}
                        />
                        <StatusBadge
                            status="paid"
                            label={t('invoices.statuses.paid')}
                        />
                        <StatusBadge
                            status="refunded"
                            label={t('invoices.statuses.refunded')}
                        />
                        <StatusBadge
                            status="void"
                            label={t('invoices.statuses.void')}
                        />
                    </div>
                </Block>

                <Block title={t('dev.sections.surfaces')}>
                    <Section
                        title={s('work_done')}
                        action={
                            <Button variant="tertiary" size="sm">
                                <Pencil /> {s('edit')}
                            </Button>
                        }
                    >
                        <div className="divide-y rounded-2xl border bg-white/60">
                            <ListRow
                                href="#"
                                title={s('customer')}
                                subtitle={s('address')}
                                meta={
                                    <StatusBadge
                                        status="scheduled"
                                        label={t('jobs.statuses.scheduled')}
                                    />
                                }
                                chevron
                            />
                            <ListRow
                                title={s('appliance')}
                                subtitle={s('address')}
                                trailing={
                                    <IconButton label={s('call')}>
                                        <Phone />
                                    </IconButton>
                                }
                            />
                        </div>
                    </Section>
                </Block>

                <Block title={t('dev.sections.controls')}>
                    <SearchField
                        value={search}
                        onSearch={setSearch}
                        placeholder={s('search')}
                    />
                    <FilterChips
                        label={s('search')}
                        selected={chips}
                        onChange={(value) =>
                            setChips((current) =>
                                current.includes(value)
                                    ? current.filter((c) => c !== value)
                                    : [...current, value],
                            )
                        }
                        chips={[
                            {
                                value: 'overdue',
                                label: t('ui.overdue'),
                                count: 2,
                                danger: true,
                            },
                            {
                                value: 'schedule',
                                label: s('needs_scheduling'),
                                count: 1,
                            },
                            {
                                value: 'parts',
                                label: s('waiting_parts'),
                                count: 1,
                            },
                            { value: 'hold', label: s('on_hold'), count: 0 },
                        ]}
                    />
                    <SegmentedControl
                        label={t('dev.sections.controls')}
                        value={segment}
                        onChange={setSegment}
                        options={[
                            { value: 'day', label: s('day') },
                            { value: 'week', label: s('week') },
                            { value: 'map', label: s('map') },
                        ]}
                    />
                </Block>

                <Block title={t('dev.sections.fields')}>
                    <FormField
                        id="dev-phone"
                        label={s('phone')}
                        hint="+1 604 555 0142"
                    >
                        <PhoneInput
                            id="dev-phone"
                            value={phone}
                            onChange={setPhone}
                        />
                    </FormField>
                    <FormField id="dev-address" label={s('address_label')}>
                        <AddressInput
                            id="dev-address"
                            value={address}
                            onChange={setAddress}
                            onPick={(_, text) => setAddress(text)}
                            country="CA"
                        />
                    </FormField>
                    <FormField id="dev-tags" label={s('tags')}>
                        <TagInput
                            id="dev-tags"
                            value={tags}
                            onChange={setTags}
                            suggestions={[
                                s('tag_vip'),
                                s('tag_strata'),
                                s('tag_net30'),
                            ]}
                        />
                    </FormField>
                    <FormField id="dev-windows" label={s('phones')}>
                        <EditableList
                            items={windows}
                            onChange={setWindows}
                            itemKey={(_, i) => i}
                            addLabel={s('add_window')}
                            newItem={() => ({ start: '15:00', end: '17:00' })}
                            renderItem={(item, update) => (
                                <div className="grid grid-cols-2 gap-2">
                                    <Input
                                        type="time"
                                        value={item.start}
                                        onChange={(e) =>
                                            update({
                                                ...item,
                                                start: e.target.value,
                                            })
                                        }
                                    />
                                    <Input
                                        type="time"
                                        value={item.end}
                                        onChange={(e) =>
                                            update({
                                                ...item,
                                                end: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                            )}
                        />
                    </FormField>
                </Block>

                <Block title={t('dev.sections.booking')}>
                    <DateChips
                        today={today}
                        value={day}
                        onChange={setDay}
                        label={s('when')}
                    />
                    <TimeWindowChips
                        label={s('window')}
                        windows={WINDOWS}
                        value={windowStart}
                        onChange={setWindowStart}
                        leading={{ value: 'now', label: s('now') }}
                    />
                </Block>

                <Block title={t('dev.sections.text')}>
                    <div className="space-y-1">
                        <MoneyText minor={17920} size="lg" />
                        <div className={row}>
                            <MoneyText minor={9500} />
                            <MoneyText minor={-9500} />
                        </div>
                        <div className={row}>
                            <DateText
                                value={new Date().toISOString()}
                                format="full-day"
                            />
                            <DateText
                                value={new Date().toISOString()}
                                format="time"
                            />
                            <DateText value={today} format="date-only" />
                        </div>
                    </div>
                </Block>

                <Block title={t('dev.sections.feedback')}>
                    <div className={row}>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmOpen(true)}
                        >
                            {s('confirm')}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => setSheetOpen(true)}
                        >
                            {s('sheet')}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                toast.withUndo(
                                    s('toast_text'),
                                    t('ui.undo'),
                                    () => undefined,
                                )
                            }
                        >
                            {s('toast')}
                        </Button>
                    </div>
                    <EmptyState
                        icon={Sun}
                        title={s('empty_title')}
                        description={s('empty_text')}
                        action={
                            <Button>
                                <Plus /> {s('book')}
                            </Button>
                        }
                    />
                    <div className="space-y-2">
                        <Skeleton className="h-5 w-2/3" />
                        <Skeleton className="h-5 w-1/2" />
                        <Skeleton className="h-24 w-full rounded-2xl" />
                    </div>
                </Block>

                <Block title={t('dev.sections.navigation')}>
                    <div className="overflow-hidden rounded-2xl border">
                        <AppBar
                            title={t('jobs.title')}
                            subtitle={s('jobs_subtitle')}
                            back="#"
                            actions={
                                <IconButton
                                    label={s('search')}
                                    variant="header"
                                >
                                    <Search />
                                </IconButton>
                            }
                        />
                    </div>
                    <BottomNav
                        label={t('nav.tabs')}
                        inline
                        items={[
                            {
                                key: 'today',
                                label: s('today'),
                                icon: Sun,
                                href: '#',
                                active: true,
                            },
                            {
                                key: 'calendar',
                                label: s('calendar'),
                                icon: CalendarDays,
                                href: '#',
                            },
                            {
                                key: 'book',
                                label: s('book'),
                                icon: Plus,
                                href: '#',
                                primary: true,
                            },
                            {
                                key: 'jobs',
                                label: s('jobs'),
                                icon: ClipboardList,
                                href: '#',
                                badge: 3,
                            },
                            {
                                key: 'more',
                                label: s('more'),
                                icon: MoreHorizontal,
                                href: '#',
                            },
                        ]}
                    />
                </Block>
            </div>

            <Fab
                label={s('trip')}
                icon={<Car />}
                variant="secondary"
                className="bottom-[calc(11rem+env(safe-area-inset-bottom))] md:bottom-24"
            />
            <StickyActionBar>
                <Button variant="outline">{s('start_job')}</Button>
                <Button>
                    <Car /> {s('on_my_way')}
                </Button>
            </StickyActionBar>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={s('confirm_title')}
                description={s('confirm_text')}
                confirmLabel={s('delete_job')}
                destructive
                onConfirm={() => setConfirmOpen(false)}
            />
            <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                <SheetContent side="bottom" className="px-4">
                    <SheetHeader className="px-0">
                        <SheetTitle>{s('sheet_title')}</SheetTitle>
                    </SheetHeader>
                    <div className="grid gap-2 pb-2">
                        {JOB_STATUSES.slice(0, 5).map((status) => (
                            <Button
                                key={status}
                                variant="outline"
                                className="justify-start"
                                onClick={() => setSheetOpen(false)}
                            >
                                <StatusBadge
                                    status={status}
                                    label={t(`jobs.statuses.${status}`)}
                                />
                            </Button>
                        ))}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}

UiKit.layout = {
    breadcrumbs: [{ title: 'dev.title', href: '/dev/ui' }],
};
