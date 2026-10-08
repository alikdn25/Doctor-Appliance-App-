import { useForm, usePage } from '@inertiajs/react';
import { Car, LocateFixed } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { AddressAutocomplete } from '@/components/customers/address-autocomplete';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useTrans } from '@/lib/i18n';
import { quick } from '@/routes/trips';

/**
 * "+ Trip" on My Jobs: type where you are driving (a parts store), Save. The phone's position is the start;
 * the server measures the road distance and counts the next job from this stop.
 */
export function QuickTripSheet({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const t = useTrans();
    const { auth } = usePage().props;
    const [locating, setLocating] = useState(false);
    const form = useForm({
        to_address: '',
        to_point: '',
        latitude: '' as string | number,
        longitude: '' as string | number,
    });

    // The position is asked for when the sheet opens, so it is ready by the time the address is typed.
    useEffect(() => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();

        if (!('geolocation' in navigator)) {
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                form.setData((d) => ({
                    ...d,
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                }));
                setLocating(false);
            },
            // No permission or no signal: the server starts from the last point of the day.
            () => setLocating(false),
            { enableHighAccuracy: true, timeout: 10_000, maximumAge: 60_000 },
        );
        // Only when the sheet opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(quick().url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                className="mx-auto max-w-2xl gap-3 rounded-t-3xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
            >
                <SheetHeader className="p-0 pr-8">
                    <SheetTitle className="flex items-center gap-2">
                        <Car className="size-5" /> {t('trips.quick_title')}
                    </SheetTitle>
                    <SheetDescription>{t('trips.quick_hint')}</SheetDescription>
                </SheetHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div>
                        <AddressAutocomplete
                            id="quick-trip-to"
                            aria-label={t('trips.quick_to')}
                            placeholder={t('trips.quick_to')}
                            autoFocus
                            value={form.data.to_address}
                            country={auth.company?.country ?? 'US'}
                            onChange={(value) =>
                                form.setData((d) => ({
                                    ...d,
                                    to_address: value,
                                    to_point: '',
                                }))
                            }
                            onPick={(address, text) =>
                                form.setData((d) => ({
                                    ...d,
                                    // The place as Google shows it: "Reliable Parts, Dawson St, Burnaby, BC, Canada".
                                    to_address: text.slice(0, 255),
                                    to_point:
                                        address.latitude && address.longitude
                                            ? `${address.latitude},${address.longitude}`
                                            : '',
                                }))
                            }
                        />
                        <InputError message={form.errors.to_address} />
                    </div>
                    {locating && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <LocateFixed className="size-4 animate-pulse" />
                            {t('trips.locating')}
                        </p>
                    )}
                    <Button
                        type="submit"
                        className="h-14 w-full text-base"
                        disabled={
                            form.processing ||
                            form.data.to_address.trim() === ''
                        }
                    >
                        {t('trips.quick_save')}
                    </Button>
                </form>
            </SheetContent>
        </Sheet>
    );
}
