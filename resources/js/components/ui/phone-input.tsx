import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { formatAsYouType, phoneCharacters } from '@/lib/phone';

/**
 * Phone number field: the phone keypad, only characters a number can contain. With a country, the number is
 * formatted as it is typed ("(604) 555-0142"); deleting is left alone so brackets never come back. Numbers without
 * + are read as numbers of the company's country on the server and stored in E.164.
 */
export function PhoneInput({
    value,
    onChange,
    country,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & {
    value: string;
    onChange: (value: string) => void;
    country?: string;
}) {
    return (
        <Input
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            value={value}
            onChange={(e) => {
                const typed = phoneCharacters(e.target.value);
                onChange(
                    country && typed.length > value.length
                        ? formatAsYouType(typed, country)
                        : typed,
                );
            }}
            {...props}
        />
    );
}
