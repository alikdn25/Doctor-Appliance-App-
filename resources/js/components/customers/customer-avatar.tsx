import { Building2, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTrans } from '@/lib/i18n';
import { avatar } from '@/routes/customers';

export type AvatarStyle = 'auto' | 'neutral' | 'man' | 'woman';
export type AvatarIcon = Exclude<AvatarStyle, 'auto'> | 'business';

const sizes = { md: 'size-11', lg: 'size-[60px]' } as const;

/**
 * Cartoon 3D face (man / woman), or an icon for a business or an unknown person.
 * White outline and soft shadow; 60 px in work lists (docs/DESIGN.md).
 */
export function CustomerAvatar({
    icon,
    size = 'md',
}: {
    icon: AvatarIcon;
    size?: keyof typeof sizes;
}) {
    const t = useTrans();
    const label = t(`customers.icons.${icon}`);
    if (icon === 'man' || icon === 'woman') {
        return (
            <img
                src={`/images/avatars/${icon}.png`}
                alt={label}
                className={`da-avatar ${sizes[size]} shrink-0 rounded-full object-cover`}
            />
        );
    }
    return (
        <span
            className={`da-avatar ${sizes[size]} flex shrink-0 items-center justify-center rounded-full bg-[linear-gradient(90deg,#F1F7FF,#B3D4FF)] text-[#1E3A8A]`}
            role="img"
            aria-label={label}
        >
            {icon === 'business' ? (
                <Building2 aria-hidden="true" className="size-1/2" />
            ) : (
                <UserRound aria-hidden="true" className="size-1/2" />
            )}
        </span>
    );
}

export function useNameAvatar(name: string, style: AvatarStyle): AvatarIcon {
    const [suggestion, setSuggestion] = useState<AvatarIcon>('neutral');
    useEffect(() => {
        setSuggestion('neutral');
        if (style !== 'auto' || name.trim() === '') return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(avatar({ query: { first_name: name } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) =>
                    response.ok ? response.json() : { icon: 'neutral' },
                )
                .then((data: { icon: AvatarIcon }) => setSuggestion(data.icon))
                .catch(() => undefined);
        }, 300);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [name, style]);
    return style === 'auto' ? suggestion : style;
}
