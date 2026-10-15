import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Live search: results follow the typing (debounced), a clear button empties it. 16 px text so phones do not zoom.
 */
export function SearchField({
    value,
    onSearch,
    placeholder,
    delay = 250,
    autoFocus = false,
    className,
}: {
    value: string;
    onSearch: (value: string) => void;
    placeholder?: string;
    delay?: number;
    autoFocus?: boolean;
    className?: string;
}) {
    const t = useTrans();
    const [text, setText] = useState(value);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => setText(value), [value]);
    useEffect(() => () => window.clearTimeout(timer.current), []);

    const change = (next: string) => {
        setText(next);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => onSearch(next), delay);
    };

    return (
        <div className={cn('relative', className)} data-slot="search-field">
            <Search
                className="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                type="search"
                inputMode="search"
                enterKeyHint="search"
                value={text}
                autoFocus={autoFocus}
                onChange={(e) => change(e.target.value)}
                placeholder={placeholder ?? t('ui.search')}
                aria-label={placeholder ?? t('ui.search')}
                className="da-input h-12 w-full border pr-11 pl-11 text-base outline-none focus-visible:border-link focus-visible:ring-[3px] focus-visible:ring-link/15 md:h-10 [&::-webkit-search-cancel-button]:hidden"
            />
            {text !== '' && (
                <button
                    type="button"
                    onClick={() => change('')}
                    aria-label={t('ui.clear')}
                    className="absolute top-1/2 right-1 inline-flex size-11 -translate-y-1/2 items-center justify-center text-muted-foreground md:size-9"
                >
                    <X className="size-5" />
                </button>
            )}
        </div>
    );
}
