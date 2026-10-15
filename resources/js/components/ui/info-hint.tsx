import { Info } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * A heading with a small (i): the explanation opens on tap instead of taking lines on the screen (UX-REDESIGN §7.5).
 */
export function InfoHint({
    title,
    hint,
    className,
}: {
    title: ReactNode;
    hint: string;
    className?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className={cn('space-y-1', className)}>
            <div className="flex items-center gap-1">
                <h3 className="text-sm font-medium">{title}</h3>
                <button
                    type="button"
                    aria-label={hint}
                    aria-expanded={open}
                    title={hint}
                    className="flex size-8 items-center justify-center rounded-full text-muted-foreground hover:bg-black/5"
                    onClick={() => setOpen(!open)}
                >
                    <Info className="size-4" />
                </button>
            </div>
            {open && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}
