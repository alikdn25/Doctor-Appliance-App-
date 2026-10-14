import { X } from 'lucide-react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Tags typed one by one: Enter or comma adds, × removes; suggestions are offered as chips below. */
export function TagInput({
    value,
    onChange,
    suggestions = [],
    placeholder,
    id,
    className,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    suggestions?: string[];
    placeholder?: string;
    id?: string;
    className?: string;
}) {
    const t = useTrans();
    const [text, setText] = useState('');

    const add = (tag: string) => {
        const clean = tag.trim();

        if (clean !== '' && !value.some((v) => v.toLowerCase() === clean.toLowerCase())) {
            onChange([...value, clean]);
        }

        setText('');
    };

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            add(text);
        } else if (e.key === 'Backspace' && text === '' && value.length > 0) {
            onChange(value.slice(0, -1));
        }
    };

    const offered = suggestions.filter(
        (s) => !value.includes(s) && s.toLowerCase().includes(text.toLowerCase()),
    );

    return (
        <div className={cn('space-y-2', className)} data-slot="tag-input">
            <div className="da-input flex min-h-12 flex-wrap items-center gap-1.5 border px-2 py-1.5">
                {value.map((tag) => (
                    <span
                        key={tag}
                        className="da-soft inline-flex h-8 items-center gap-1 rounded-full pr-1 pl-3 text-sm"
                    >
                        {tag}
                        <button
                            type="button"
                            onClick={() => onChange(value.filter((v) => v !== tag))}
                            aria-label={t('ui.remove_tag', { tag })}
                            className="inline-flex size-7 items-center justify-center rounded-full"
                        >
                            <X className="size-4" />
                        </button>
                    </span>
                ))}
                <input
                    id={id}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={onKeyDown}
                    onBlur={() => text !== '' && add(text)}
                    placeholder={value.length === 0 ? placeholder : undefined}
                    className="h-9 min-w-24 flex-1 bg-transparent px-1 text-base outline-none"
                />
            </div>
            {offered.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {offered.slice(0, 8).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => add(s)}
                            className="da-chip da-press h-9 rounded-full px-3 text-sm"
                        >
                            + {s}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
