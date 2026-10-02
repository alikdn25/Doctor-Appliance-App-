import { usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import type { ComponentProps, KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';

/** An address picked from Google Places, split into our fields. */
export type PickedAddress = {
    line1: string;
    unit?: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    google_place_id: string;
    latitude: string;
    longitude: string;
};

/** Address fields that, edited by hand, no longer match the picked place. */
export const GEOCODED_FIELDS = [
    'line1',
    'city',
    'region',
    'postal_code',
    'country',
] as const;

/** Empties the place ID and coordinates (the address was changed by hand). */
export const clearedPlace = {
    google_place_id: '',
    latitude: '',
    longitude: '',
};

// The few parts of the Maps JavaScript API (Places API (New)) used here; no @types package needed.
type AddressComponent = {
    longText: string | null;
    shortText: string | null;
    types: string[];
};
type Place = {
    id: string;
    addressComponents?: AddressComponent[];
    location?: { lat: () => number; lng: () => number } | null;
    fetchFields: (options: { fields: string[] }) => Promise<unknown>;
};
type Suggestion = {
    placePrediction: {
        placeId: string;
        text: { text: string };
        mainText?: { text: string } | null;
        secondaryText?: { text: string } | null;
        toPlace: () => Place;
    } | null;
};
type PlacesLibrary = {
    AutocompleteSuggestion: {
        fetchAutocompleteSuggestions: (request: {
            input: string;
            includedRegionCodes?: string[];
            sessionToken?: unknown;
            language?: string;
        }) => Promise<{ suggestions: Suggestion[] }>;
    };
    AutocompleteSessionToken: new () => unknown;
};

declare global {
    interface Window {
        google?: {
            maps?: { importLibrary?: (name: string) => Promise<unknown> };
        };
        __placesReady?: () => void;
    }
}

let loading: Promise<PlacesLibrary> | null = null;

/** Loads the Maps JavaScript API once, then its Places library. */
function loadPlaces(key: string): Promise<PlacesLibrary> {
    if (loading) {
        return loading;
    }

    loading = new Promise<PlacesLibrary>((resolve, reject) => {
        const ready = () =>
            window.google?.maps?.importLibrary
                ? (
                      window.google.maps.importLibrary(
                          'places',
                      ) as Promise<PlacesLibrary>
                  ).then(resolve, reject)
                : reject(new Error('Google Maps not loaded'));

        if (window.google?.maps?.importLibrary) {
            void ready();

            return;
        }

        window.__placesReady = ready;
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&loading=async&callback=__placesReady`;
        script.async = true;
        script.onerror = () => {
            loading = null;
            reject(new Error('Google Maps failed to load'));
        };
        document.head.append(script);
    });

    return loading;
}

// Countries that write the house number before the street ("123 Main St"); elsewhere "Hauptstraße 12".
const NUMBER_FIRST = new Set([
    'US',
    'CA',
    'GB',
    'IE',
    'AU',
    'NZ',
    'FR',
    'LU',
    'ZA',
    'SG',
    'PH',
    'MY',
    'IN',
    'HK',
    'IL',
    'SA',
    'AE',
    'TH',
    'JM',
    'TT',
    'BS',
    'PR',
]);

/** Splits Google's address components into our address fields. */
export function toAddress(
    place: Place,
    fallbackCountry: string,
): PickedAddress {
    const parts = place.addressComponents ?? [];
    const find = (type: string, short = false) => {
        const part = parts.find((c) => c.types.includes(type));

        return (short ? part?.shortText : part?.longText) ?? '';
    };

    const country = (find('country', true) || fallbackCountry).toUpperCase();
    const number = find('street_number');
    const route = find('route');
    const line1 = NUMBER_FIRST.has(country)
        ? [number, route].filter(Boolean).join(' ')
        : [route, number].filter(Boolean).join(' ');
    // UK and Ireland: the county is level 2 (level 1 is England, Scotland …).
    const region = ['GB', 'IE'].includes(country)
        ? find('administrative_area_level_2')
        : find('administrative_area_level_1', true);

    return {
        line1: line1 || find('premise') || find('point_of_interest'),
        unit: find('subpremise') || undefined,
        city:
            find('locality') ||
            find('postal_town') ||
            find('sublocality_level_1') ||
            find('administrative_area_level_3') ||
            find('administrative_area_level_2'),
        region,
        postal_code: find('postal_code').toUpperCase(),
        country,
        google_place_id: place.id,
        latitude: place.location ? place.location.lat().toFixed(7) : '',
        longitude: place.location ? place.location.lng().toFixed(7) : '',
    };
}

/**
 * Street address input with Google Places suggestions in the company's country. Without a Maps key (or when
 * Google cannot be reached) it is a plain input, so the address is typed by hand as before.
 */
export function AddressAutocomplete({
    value,
    onChange,
    onPick,
    country,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value' | 'onChange'> & {
    value: string;
    onChange: (value: string) => void;
    onPick: (address: PickedAddress) => void;
    /** ISO 3166-1 alpha-2; suggestions are limited to it. */
    country: string;
}) {
    const { auth } = usePage().props;
    const key = auth.company?.google_maps_key ?? null;
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [active, setActive] = useState(-1);
    const [open, setOpen] = useState(false);
    const library = useRef<PlacesLibrary | null>(null);
    const session = useRef<unknown>(null);
    const timer = useRef<number | undefined>(undefined);
    const typed = useRef(false);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    // Suggestions while typing (debounced); only after the person typed, not when the form is filled in.
    useEffect(() => {
        if (!key || !typed.current) {
            return;
        }

        window.clearTimeout(timer.current);

        if (value.trim().length < 3) {
            setSuggestions([]);

            return;
        }

        timer.current = window.setTimeout(async () => {
            try {
                const places = library.current ?? (await loadPlaces(key));
                library.current = places;
                session.current ??= new places.AutocompleteSessionToken();
                const { suggestions: found } =
                    await places.AutocompleteSuggestion.fetchAutocompleteSuggestions(
                        {
                            input: value,
                            includedRegionCodes: [country.toLowerCase()],
                            sessionToken: session.current,
                        },
                    );
                setSuggestions(
                    found.filter((s) => s.placePrediction).slice(0, 5),
                );
                setActive(-1);
                setOpen(true);
            } catch {
                // No suggestions (offline, key restrictions …): the field still works by hand.
                setSuggestions([]);
            }
        }, 250);
    }, [value, key, country]);

    const pick = async (suggestion: Suggestion) => {
        const prediction = suggestion.placePrediction;
        setOpen(false);
        setSuggestions([]);
        typed.current = false;

        if (!prediction) {
            return;
        }

        try {
            const place = prediction.toPlace();
            await place.fetchFields({
                fields: ['addressComponents', 'location'],
            });
            // The pick ends the billing session; the next search starts a new one.
            session.current = null;
            onPick(toAddress(place, country));
        } catch {
            onChange(prediction.mainText?.text ?? prediction.text.text);
        }
    };

    const keyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (!open || suggestions.length === 0) {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % suggestions.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i <= 0 ? suggestions.length - 1 : i - 1));
        } else if (e.key === 'Enter' && active >= 0) {
            e.preventDefault();
            void pick(suggestions[active]);
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    if (!key) {
        return (
            <Input
                {...props}
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    }

    return (
        <div className="relative">
            <Input
                {...props}
                value={value}
                autoComplete="off"
                role="combobox"
                aria-expanded={open && suggestions.length > 0}
                aria-autocomplete="list"
                onChange={(e) => {
                    typed.current = true;
                    onChange(e.target.value);
                }}
                onKeyDown={keyDown}
                onBlur={() => setOpen(false)}
                onFocus={() => suggestions.length > 0 && setOpen(true)}
            />
            {open && suggestions.length > 0 && (
                <ul
                    role="listbox"
                    className="absolute inset-x-0 top-full z-50 mt-1 overflow-hidden rounded-md border bg-popover text-sm shadow-md"
                >
                    {suggestions.map((s, i) => (
                        <li
                            key={s.placePrediction?.placeId ?? i}
                            role="option"
                            aria-selected={i === active}
                            // mousedown, so the input's blur does not close the list before the pick.
                            onMouseDown={(e) => {
                                e.preventDefault();
                                void pick(s);
                            }}
                            className={
                                i === active
                                    ? 'flex min-h-11 cursor-pointer items-start gap-2 bg-accent px-3 py-2'
                                    : 'flex min-h-11 cursor-pointer items-start gap-2 px-3 py-2 hover:bg-accent'
                            }
                        >
                            <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0">
                                <span className="block truncate">
                                    {s.placePrediction?.mainText?.text ??
                                        s.placePrediction?.text.text}
                                </span>
                                {s.placePrediction?.secondaryText && (
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {s.placePrediction.secondaryText.text}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                    <li className="px-3 py-1 text-right text-[10px] text-muted-foreground">
                        Google
                    </li>
                </ul>
            )}
        </div>
    );
}
