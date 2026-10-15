import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { IconButton } from '@/components/ui/icon-button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ActionSheetItem =
    | {
          key: string;
          label: string;
          /** A short note on the right, e.g. "with a reason". */
          hint?: string;
          icon?: ReactNode;
          onSelect: () => void;
          destructive?: boolean;
      }
    | 'separator';

/**
 * The "⋯" of a screen as a sheet from the bottom (UX-REDESIGN §6.2, §15.4): reachable with one hand. Rare and
 * dangerous actions of the screen live here.
 */
export function ActionSheet({
    title,
    items,
    variant = 'header',
}: {
    title: string;
    /** Items the person may not use are passed as false and left out. */
    items: (ActionSheetItem | false | null | undefined)[];
    variant?: 'raised' | 'header' | 'plain';
}) {
    const t = useTrans();
    const [open, setOpen] = useState(false);
    const present = items.filter((item): item is ActionSheetItem =>
        Boolean(item),
    );
    // No separator at the ends or twice in a row.
    const shown = present.filter(
        (item, index) =>
            item !== 'separator' ||
            (index > 0 &&
                index < present.length - 1 &&
                present[index - 1] !== 'separator'),
    );

    if (!shown.some((item) => item !== 'separator')) {
        return null;
    }

    return (
        <>
            <IconButton
                label={t('ui.more_actions')}
                variant={variant}
                onClick={() => setOpen(true)}
                data-test="action-sheet-button"
            >
                <MoreHorizontal />
            </IconButton>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent
                    side="bottom"
                    className="gap-2 px-3 pt-4"
                    data-test="action-sheet"
                >
                    <SheetHeader className="p-0 px-2 pr-12">
                        <SheetTitle>{title}</SheetTitle>
                        <SheetDescription className="sr-only">
                            {t('ui.more_actions')}
                        </SheetDescription>
                    </SheetHeader>
                    <div className="pb-[env(safe-area-inset-bottom)]">
                        {shown.map((item, index) =>
                            item === 'separator' ? (
                                <div
                                    key={`sep-${index}`}
                                    className="my-1 border-t"
                                />
                            ) : (
                                <button
                                    key={item.key}
                                    type="button"
                                    className={cn(
                                        'flex min-h-12 w-full items-center gap-3 rounded-xl px-2 text-left text-base font-medium hover:bg-black/5 [&_svg]:size-5 [&_svg]:shrink-0',
                                        item.destructive && 'text-danger',
                                    )}
                                    onClick={() => {
                                        setOpen(false);
                                        item.onSelect();
                                    }}
                                >
                                    {item.icon}
                                    <span className="flex-1">{item.label}</span>
                                    {item.hint && (
                                        <span className="text-sm font-normal text-muted-foreground">
                                            {item.hint}
                                        </span>
                                    )}
                                </button>
                            ),
                        )}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
