import { Star, UserX } from 'lucide-react';
import { useInitials } from '@/hooks/use-initials';
import { useTrans } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type TechnicianCard = {
    /** null = Unassigned. */
    id: number | null;
    name: string;
    /** The person booking: "Me", a gold border and a star. */
    me?: boolean;
    /** Free (green dot) or busy (red dot) in the chosen window; undefined = no dot (no time chosen). */
    free?: boolean;
    /** "Busy 9–11" for screen readers and the tooltip. */
    hint?: string;
};

/**
 * Who goes (Book customer): one horizontal row of raised cards, avatar and name. "Me" comes first with a gold border
 * and a star, then the team by name, Unassigned last. A dot says free (green) or busy (red) in the chosen window; a
 * busy person can still be picked.
 */
export function TechnicianCards({
    label,
    cards,
    value,
    onChange,
}: {
    label: string;
    cards: TechnicianCard[];
    value: number | null;
    onChange: (id: number | null) => void;
}) {
    const t = useTrans();
    const initials = useInitials();

    return (
        <div
            role="radiogroup"
            aria-label={label}
            className="-mx-4 flex snap-x [scrollbar-width:none] gap-2 overflow-x-auto px-4 pt-1.5 pb-2 [&::-webkit-scrollbar]:hidden"
            data-test="technician-cards"
        >
            {cards.map((card) => {
                const chosen = value === card.id;

                return (
                    <button
                        key={card.id ?? 'none'}
                        type="button"
                        role="radio"
                        aria-checked={chosen}
                        title={card.hint}
                        data-test={
                            card.me
                                ? 'technician-me'
                                : card.id === null
                                  ? 'technician-unassigned'
                                  : 'technician-card'
                        }
                        onClick={() => onChange(card.id)}
                        className={cn(
                            'da-chip da-press relative flex w-20 shrink-0 snap-start flex-col items-center gap-1 rounded-2xl px-1.5 pt-2.5 pb-2 text-center',
                            card.me && 'ring-2 ring-amber-400',
                        )}
                    >
                        {card.me && (
                            <Star
                                aria-hidden="true"
                                className="absolute -top-1.5 -right-1.5 size-4 fill-amber-400 text-amber-500 drop-shadow"
                            />
                        )}
                        <span className="relative">
                            <span
                                className={cn(
                                    'flex size-10 items-center justify-center rounded-full text-sm font-bold shadow-sm ring-2 ring-white',
                                    chosen
                                        ? 'bg-white/25 text-white'
                                        : 'bg-primary/12 text-link',
                                )}
                            >
                                {card.id === null ? (
                                    <UserX className="size-5" />
                                ) : (
                                    initials(card.name)
                                )}
                            </span>
                            {card.free !== undefined && (
                                <span
                                    data-test={
                                        card.free
                                            ? 'technician-free'
                                            : 'technician-busy'
                                    }
                                    className={cn(
                                        'absolute -right-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-white',
                                        card.free
                                            ? 'bg-emerald-500'
                                            : 'bg-red-500',
                                    )}
                                />
                            )}
                        </span>
                        <span className="w-full truncate text-xs font-semibold">
                            {card.me ? t('jobs.quick.me') : card.name}
                        </span>
                        {card.hint && (
                            <span className="sr-only">{card.hint}</span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
