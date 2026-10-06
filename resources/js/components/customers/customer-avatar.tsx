import { router } from '@inertiajs/react';
import { Building2, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTrans } from '@/lib/i18n';
import { avatar, icon as iconRoute } from '@/routes/customers';

export type AvatarStyle = 'auto' | 'man' | 'woman' | 'couple';
/** 'neutral' = the face is not known yet: the avatar shows initials. */
export type AvatarIcon = 'man' | 'woman' | 'couple' | 'neutral' | 'business';

const sizes = {
    md: 'size-11 text-base',
    lg: 'size-[60px] text-xl',
    xl: 'size-20 text-2xl',
} as const;

/** Up to two initials of a name ("Anna Kim" → "AK"). */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => Array.from(part)[0]?.toLocaleUpperCase() ?? '')
        .join('');
}

/**
 * Cartoon 3D face (man / woman); initials when the face is not known yet; an icon for a business.
 * White outline and soft shadow; 60 px in work lists (docs/DESIGN.md).
 */
export function CustomerAvatar({
    icon,
    name,
    size = 'md',
}: {
    icon: AvatarIcon;
    name?: string;
    size?: keyof typeof sizes;
}) {
    const t = useTrans();
    const label = t(`customers.icons.${icon}`);
    if (icon === 'couple') {
        // Two faces in one circle: the woman behind on the left, the man in front on the right.
        return (
            <span
                role="img"
                aria-label={label}
                className={`da-avatar ${sizes[size]} relative inline-block shrink-0 overflow-hidden rounded-full bg-[linear-gradient(90deg,#F1F7FF,#B3D4FF)]`}
            >
                <img
                    src="/images/avatars/woman.png"
                    alt=""
                    className="absolute top-[12%] -left-[14%] size-[82%] rounded-full object-cover"
                />
                <img
                    src="/images/avatars/man.png"
                    alt=""
                    className="absolute top-[18%] -right-[14%] size-[82%] rounded-full object-cover ring-2 ring-white"
                />
            </span>
        );
    }
    if (icon === 'man' || icon === 'woman') {
        return (
            <img
                src={`/images/avatars/${icon}.png`}
                alt={label}
                className={`da-avatar ${sizes[size]} shrink-0 rounded-full object-cover`}
            />
        );
    }
    const letters = icon === 'neutral' && name ? initials(name) : '';
    return (
        <span
            className={`da-avatar ${sizes[size]} flex shrink-0 items-center justify-center rounded-full bg-[linear-gradient(90deg,#F1F7FF,#B3D4FF)] font-bold text-[#1E3A8A]`}
            role="img"
            aria-label={label}
        >
            {letters !== '' ? (
                <span aria-hidden="true">{letters}</span>
            ) : icon === 'business' ? (
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

/**
 * The avatar as a button: one tap opens Man / Woman / Couple / Automatic and saves the choice.
 * For names that fit both (e.g. Sasha) the office or technician picks the face once.
 */
export function CustomerAvatarPicker({
    customerId,
    icon,
    style,
    name,
    size = 'md',
}: {
    customerId: number;
    icon: AvatarIcon;
    style: AvatarStyle;
    name?: string;
    size?: keyof typeof sizes;
}) {
    const t = useTrans();
    if (icon === 'business')
        return <CustomerAvatar icon={icon} name={name} size={size} />;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="shrink-0 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={t('customers.change_icon')}
                title={t('customers.change_icon')}
            >
                <CustomerAvatar icon={icon} name={name} size={size} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuLabel>
                    {t('customers.change_icon')}
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={style}
                    onValueChange={(value) =>
                        router.patch(
                            iconRoute(customerId).url,
                            { avatar_style: value },
                            { preserveScroll: true },
                        )
                    }
                >
                    {(['man', 'woman', 'couple', 'auto'] as const).map(
                        (option) => (
                            <DropdownMenuRadioItem
                                key={option}
                                value={option}
                                className="min-h-11"
                            >
                                {t(`customers.icons.${option}`)}
                            </DropdownMenuRadioItem>
                        ),
                    )}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
