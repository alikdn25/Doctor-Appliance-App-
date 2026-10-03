import { Building2, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTrans } from '@/lib/i18n';
import { avatar } from '@/routes/customers';

export type AvatarStyle = 'auto' | 'neutral' | 'man' | 'woman';
export type AvatarIcon = Exclude<AvatarStyle, 'auto'> | 'business';

export function CustomerAvatar({ icon }: { icon: AvatarIcon }) {
    const t = useTrans();
    return (
        <span
            className="flex size-11 shrink-0 items-center justify-center rounded-full bg-muted"
            role="img"
            aria-label={t(`customers.icons.${icon}`)}
        >
            {icon === 'man' || icon === 'woman' ? (
                <span aria-hidden="true" className="text-2xl">
                    {icon === 'man' ? '👨' : '👩'}
                </span>
            ) : icon === 'business' ? (
                <Building2 aria-hidden="true" className="size-5" />
            ) : (
                <UserRound aria-hidden="true" className="size-5" />
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
