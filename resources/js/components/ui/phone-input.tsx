import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { phoneCharacters } from '@/lib/phone';

/**
 * Phone number field: the phone keypad, only characters a number can contain. Numbers without + are read as
 * numbers of the company's country on the server and stored in E.164.
 */
export function PhoneInput({
    value,
    onChange,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <Input
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            value={value}
            onChange={(e) => onChange(phoneCharacters(e.target.value))}
            {...props}
        />
    );
}
