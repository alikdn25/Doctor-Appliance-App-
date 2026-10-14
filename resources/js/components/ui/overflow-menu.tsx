import { MoreHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { IconButton } from '@/components/ui/icon-button';
import { useTrans } from '@/lib/i18n';

export type OverflowItem =
    | {
          key: string;
          label: string;
          icon?: ReactNode;
          onSelect: () => void;
          destructive?: boolean;
          disabled?: boolean;
      }
    | 'separator';

/** The "⋯" menu for rare actions of a screen or a row (rare and dangerous actions live here, not on the screen). */
export function OverflowMenu({
    items,
    label,
    variant = 'plain',
}: {
    items: OverflowItem[];
    label?: string;
    variant?: 'raised' | 'header' | 'plain';
}) {
    const t = useTrans();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <IconButton label={label ?? t('ui.more_actions')} variant={variant}>
                    <MoreHorizontal />
                </IconButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-52 rounded-2xl">
                {items.map((item, index) =>
                    item === 'separator' ? (
                        <DropdownMenuSeparator key={`sep-${index}`} />
                    ) : (
                        <DropdownMenuItem
                            key={item.key}
                            variant={item.destructive ? 'destructive' : 'default'}
                            disabled={item.disabled}
                            onSelect={item.onSelect}
                            className="min-h-11"
                        >
                            {item.icon}
                            {item.label}
                        </DropdownMenuItem>
                    ),
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
