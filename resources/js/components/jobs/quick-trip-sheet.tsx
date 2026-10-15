import { router, useForm, usePage } from '@inertiajs/react';
import { Car, History, LocateFixed, Navigation } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { navigateUrl } from '@/components/calendar/types';
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
import { index as trips, quick } from '@/routes/trips';

/**
 * "+ Trip" (UX-REDESIGN §6.3): type where you are driving (a parts store) or tap one of the recent stops, then
 * Navigate: the trip is saved and Google Maps opens with turn-by-turn directions. The phone's position is the
 * start; the server measures the road distance and counts the next job from this stop. "Save only" is for a trip
 * already driven; "Enter by hand" opens the full form (SPEC §7.13).
 */
export function QuickTripSheet({
    open,
    onOpenChange,
    onManual,
    recent = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Opens the full form (another day, a known distance); without it, Mileage opens with the form. */
    onManual?: () => void;
    /** The person's last stores and suppliers. */
    recent?: string[];
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

    const save = () =>
        form.post(quick().url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });

    // Maps is opened inside the tap itself (a phone blocks it after a network wait); the trip saves meanwhile.
    const navigate = (e: FormEvent) => {
        e.preventDefault();

        if (form.processing || form.data.to_address.trim() === '') {
            return;
        }

        window.open(
            navigateUrl(form.data.to_point || form.data.to_address),
            '_blank',
            'noopener',
        );
        save();
    };

    const empty = form.processing || form.data.to_address.trim() === '';

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
                <form onSubmit={navigate} className="space-y-3">
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
                    {recent.length > 0 && (
                        <div className="space-y-1.5" data-test="trip-recent">
                            <p className="flex items-center gap-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                <History className="size-3.5" />
                                {t('trips.recent')}
                            </p>
                            <div className="-mx-4 flex [scrollbar-width:none] gap-2 overflow-x-auto px-4 pb-1">
                                {recent.map((stop) => (
                                    <button
                                        key={stop}
                                        type="button"
                                        aria-pressed={
                                            form.data.to_address === stop
                                        }
                                        className="da-chip da-press inline-flex h-10 max-w-64 shrink-0 items-center rounded-full px-3.5 text-sm font-medium"
                                        onClick={() =>
                                            form.setData((d) => ({
                                                ...d,
                                                to_address: stop,
                                                to_point: '',
                                            }))
                                        }
                                    >
                                        <span className="truncate">{stop}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                    {locating && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <LocateFixed className="size-4 animate-pulse" />
                            {t('trips.locating')}
                        </p>
                    )}
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={empty}
                    >
                        <Navigation className="size-5" />
                        {t('trips.quick_navigate')}
                    </Button>
                    <div className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={empty}
                            onClick={save}
                        >
                            {t('trips.quick_save')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                onOpenChange(false);
                                if (onManual) {
                                    onManual();
                                } else {
                                    router.visit(
                                        trips({ query: { manual: 1 } }).url,
                                    );
                                }
                            }}
                        >
                            {t('trips.quick_manual')}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}
