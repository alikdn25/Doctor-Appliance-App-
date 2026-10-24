import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Navigation,
    Phone,
    Route as RouteIcon,
    Sparkles,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { dayLabel, navigateUrl, routeUrl } from '@/components/calendar/types';
import { ScreenHeader } from '@/components/screen-header';
import { Button } from '@/components/ui/button';
import { FilterChips } from '@/components/ui/filter-chips';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { StatusBadge } from '@/components/ui/status-badge';
import { useClock } from '@/lib/datetime';
import {
    googleMapsKeyRejected,
    loadGoogleMapsLibrary,
    onGoogleMapsKeyRejected,
} from '@/lib/google-maps';
import { sendJson } from '@/lib/http';
import { useTrans } from '@/lib/i18n';
import { useLocale } from '@/lib/locale';
import { statusLook } from '@/lib/status';
import { cn } from '@/lib/utils';
import { map as mapPage } from '@/routes';
import { show as showJob } from '@/routes/jobs';
import { order as saveOrder, plan as planRoute } from '@/routes/map';
import { transfer as transferVisits } from '@/routes/visits';

type Position = { lat: number; lng: number };

export type MapStop = {
    id: number;
    job_id: number;
    number: number;
    status: string;
    job_status: string;
    job_status_label: string;
    customer: string | null;
    phone: string | null;
    address: string | null;
    coordinates: Position | null;
    start_time: string;
    end_time: string;
    assignees: { id: number; name: string }[];
    person_id: number | null;
    position: number;
};

type Person = { id: number; name: string; assignable?: boolean };

type Leg = {
    order: number[];
    drive_minutes: number;
    distance_km: number;
    start: number;
    finish: number;
    late_minutes: number;
};

type Plan = {
    estimated: boolean;
    current: Leg;
    best: Leg;
    saved_minutes: number;
    saved_drive_minutes: number;
    saved_km: number;
    changed: boolean;
};

type Props = {
    date: string;
    today: string;
    person: string;
    people: Person[];
    stops: MapStop[];
    canManage: boolean;
    distanceUnit: 'km' | 'mi';
};

/* Minimal shapes of the Google Maps classes used here. */
type MapInstance = {
    fitBounds: (
        bounds: { north: number; south: number; east: number; west: number },
        padding: number,
    ) => void;
    setCenter: (position: Position) => void;
    setZoom: (zoom: number) => void;
};
type MapsLibrary = {
    Map: new (
        element: HTMLElement,
        options: {
            center: Position;
            zoom: number;
            mapId: string;
            disableDefaultUI?: boolean;
            zoomControl?: boolean;
            gestureHandling?: string;
        },
    ) => MapInstance;
    Polyline: new (options: {
        map: MapInstance;
        path: Position[];
        strokeColor: string;
        strokeOpacity: number;
        strokeWeight: number;
    }) => { setMap: (map: MapInstance | null) => void };
};
type Marker = { map: MapInstance | null };
type MarkerLibrary = {
    AdvancedMarkerElement: new (options: {
        map: MapInstance;
        position: Position;
        title: string;
        content: HTMLElement;
        zIndex?: number;
    }) => Marker;
};
type MatrixElement = {
    status: string;
    duration?: { value: number };
    distance?: { value: number };
};
type RoutesLibrary = {
    DistanceMatrixService: new () => {
        getDistanceMatrix: (request: {
            origins: Position[];
            destinations: Position[];
            travelMode: string;
        }) => Promise<{ rows: { elements: MatrixElement[] }[] }>;
    };
    DirectionsService: new () => {
        route: (request: {
            origin: Position;
            destination: Position;
            waypoints: { location: Position; stopover: boolean }[];
            travelMode: string;
        }) => Promise<unknown>;
    };
    DirectionsRenderer: new (options: {
        map: MapInstance;
        directions: unknown;
        suppressMarkers: boolean;
        preserveViewport: boolean;
    }) => { setMap: (map: MapInstance | null) => void };
};

const MILE = 1.609344;
const toHhmm = (minutes: number) => {
    const m = Math.max(0, Math.min(minutes, 24 * 60 - 1));

    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
};
const shiftDay = (ymd: string, days: number) => {
    const date = new Date(`${ymd}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
};
/** A stop is planned while it is still to do: scheduled, on the way or started. */
const open = (stop: MapStop) =>
    ['scheduled', 'on_the_way', 'in_progress'].includes(stop.status);

/**
 * Map tab (owner's task, October 24): every job of the day as a numbered pin on the customer's address, coloured by
 * status; a tap opens a small card (Navigate, Call, Open job, and for the office "Move to"). Route draws the day in
 * its order with the driving time and distance; Optimize offers a better order that keeps the arrival windows, and
 * Apply saves it to the calendar. Works the same in the installed app.
 */
export default function MapPage({
    date,
    today,
    person,
    people,
    stops,
    canManage,
    distanceUnit,
}: Props) {
    const t = useTrans();
    const clock = useClock();
    const locale = useLocale();
    const { auth } = usePage().props;
    const key = auth.company?.google_maps_key;
    const mapId = auth.company?.google_maps_map_id ?? 'DEMO_MAP_ID';
    const element = useRef<HTMLDivElement>(null);
    const mapRef = useRef<{
        map: MapInstance;
        maps: MapsLibrary;
    } | null>(null);
    const overlay = useRef<{ setMap: (map: MapInstance | null) => void }[]>([]);
    const [state, setState] = useState<
        'loading' | 'ready' | 'unavailable' | 'rejected'
    >(googleMapsKeyRejected() ? 'rejected' : 'loading');
    const [opened, setOpened] = useState<MapStop | null>(null);
    const [plan, setPlan] = useState<Plan | null>(null);
    const [mode, setMode] = useState<'route' | 'optimize' | null>(null);
    const [busy, setBusy] = useState(false);
    const [moveTo, setMoveTo] = useState('');
    useEffect(() => onGoogleMapsKeyRejected(() => setState('rejected')), []);

    const number = useMemo(
        () => new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }),
        [locale],
    );
    const distance = (km: number) =>
        distanceUnit === 'mi'
            ? `${number.format(km / MILE)} mi`
            : `${number.format(km)} km`;
    const duration = (minutes: number) =>
        minutes >= 60
            ? t('map.hours_minutes', {
                  hours: Math.floor(minutes / 60),
                  minutes: minutes % 60,
              })
            : t('map.minutes', { count: minutes });

    // A route is one person's day: the chosen technician, or the only one with jobs.
    const routePeople = [...new Set(stops.map((stop) => stop.person_id))];
    const routable = routePeople.length === 1;
    const routeStops = useMemo(
        () => (routable ? stops.filter(open) : []),
        [routable, stops],
    );
    const placedRoute = routeStops.filter((stop) => stop.coordinates);
    // The order shown on the pins: the suggestion while it is on screen, otherwise the day's order.
    const shownOrder = useMemo(() => {
        if (mode === 'optimize' && plan?.changed) {
            const rank = new Map(plan.best.order.map((id, i) => [id, i]));
            const done = stops.filter((stop) => !rank.has(stop.id));

            return [
                ...done,
                ...plan.best.order
                    .map((id) => stops.find((stop) => stop.id === id))
                    .filter((stop): stop is MapStop => !!stop),
            ].map((stop, i) => ({ ...stop, position: i + 1 }));
        }

        return stops;
    }, [mode, plan, stops]);
    // Memoised: the map and its pins are rebuilt only when the shown order changes.
    const placed = useMemo(
        () => shownOrder.filter((stop) => stop.coordinates !== null),
        [shownOrder],
    );

    const go = (query: Record<string, string>) =>
        router.get(
            mapPage().url,
            { date, ...(person !== 'all' ? { person } : {}), ...query },
            { preserveScroll: true, preserveState: false },
        );

    const clearOverlay = () => {
        overlay.current.forEach((item) => item.setMap(null));
        overlay.current = [];
    };

    useEffect(() => {
        if (!key || !element.current || placed.length === 0) return;
        let cancelled = false;
        const markers: Marker[] = [];
        const container = element.current;
        setState((s) => (s === 'rejected' ? s : 'loading'));
        void Promise.all([
            loadGoogleMapsLibrary<MapsLibrary>(key, 'maps'),
            loadGoogleMapsLibrary<MarkerLibrary>(key, 'marker'),
        ])
            .then(([maps, markerLibrary]) => {
                if (cancelled) return;
                const map = new maps.Map(container, {
                    center: placed[0].coordinates!,
                    zoom: 13,
                    mapId,
                    disableDefaultUI: true,
                    zoomControl: true,
                    gestureHandling: 'greedy',
                });
                mapRef.current = { map, maps };
                placed.forEach((stop) => {
                    const pin = document.createElement('button');
                    pin.type = 'button';
                    pin.className = 'da-map-pin';
                    pin.dataset.test = `map-pin-${stop.id}`;
                    pin.style.setProperty(
                        '--pin',
                        `var(--status-${statusLook(stop.job_status).tone}-text)`,
                    );
                    pin.textContent = String(stop.position);
                    const title = `${stop.position}. ${clock.range(stop.start_time, stop.end_time)} ${stop.customer ?? ''}`;
                    pin.setAttribute('aria-label', title);
                    pin.addEventListener('click', () => setOpened(stop));
                    markers.push(
                        new markerLibrary.AdvancedMarkerElement({
                            map,
                            position: stop.coordinates!,
                            title,
                            content: pin,
                            zIndex: 100 - stop.position,
                        }),
                    );
                });
                const points = placed.map((stop) => stop.coordinates!);
                if (points.length > 1) {
                    map.fitBounds(
                        {
                            north: Math.max(...points.map((p) => p.lat)),
                            south: Math.min(...points.map((p) => p.lat)),
                            east: Math.max(...points.map((p) => p.lng)),
                            west: Math.min(...points.map((p) => p.lng)),
                        },
                        56,
                    );
                }
                setState('ready');
            })
            .catch(
                () =>
                    !cancelled &&
                    setState(
                        googleMapsKeyRejected() ? 'rejected' : 'unavailable',
                    ),
            );

        return () => {
            cancelled = true;
            markers.forEach((marker) => {
                marker.map = null;
            });
            clearOverlay();
            mapRef.current = null;
            container.replaceChildren();
        };
        // The pins follow the shown order; the clock only formats their labels.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, mapId, placed]);

    /** Road times between the stops from Google, when the key allows it (up to 10 stops: 100 pairs). */
    const roadMatrix = useCallback(
        async (points: Position[]) => {
            if (!key || state !== 'ready' || points.length > 10) return null;
            try {
                const routes = await loadGoogleMapsLibrary<RoutesLibrary>(
                    key,
                    'routes',
                );
                const result =
                    await new routes.DistanceMatrixService().getDistanceMatrix({
                        origins: points,
                        destinations: points,
                        travelMode: 'DRIVING',
                    });
                const minutes: number[][] = [];
                const km: number[][] = [];
                for (const [i, row] of result.rows.entries()) {
                    minutes[i] = [];
                    km[i] = [];
                    for (const [j, cell] of row.elements.entries()) {
                        if (cell.status !== 'OK' && i !== j) return null;
                        minutes[i][j] = Math.round(
                            (cell.duration?.value ?? 0) / 60,
                        );
                        km[i][j] =
                            Math.round((cell.distance?.value ?? 0) / 10) / 100;
                    }
                }

                return { minutes, km };
            } catch {
                return null;
            }
        },
        [key, state],
    );

    /** The path on the map: by road when Google gives directions, otherwise straight lines between the pins. */
    const drawPath = async (ordered: MapStop[]) => {
        const current = mapRef.current;
        clearOverlay();
        if (!current || !key) return;
        const points = ordered
            .map((stop) => stop.coordinates)
            .filter((p): p is Position => !!p);
        if (points.length < 2) return;
        try {
            const routes = await loadGoogleMapsLibrary<RoutesLibrary>(
                key,
                'routes',
            );
            const directions = await new routes.DirectionsService().route({
                origin: points[0],
                destination: points[points.length - 1],
                waypoints: points
                    .slice(1, -1)
                    .map((location) => ({ location, stopover: true })),
                travelMode: 'DRIVING',
            });
            overlay.current.push(
                new routes.DirectionsRenderer({
                    map: current.map,
                    directions,
                    suppressMarkers: true,
                    preserveViewport: true,
                }),
            );
        } catch {
            overlay.current.push(
                new current.maps.Polyline({
                    map: current.map,
                    path: points,
                    strokeColor: '#0567f5',
                    strokeOpacity: 0.8,
                    strokeWeight: 4,
                }),
            );
        }
    };

    // The route on the map follows the plan on screen; the map may be rebuilt for the new pin numbers first.
    useEffect(() => {
        if (state !== 'ready' || !plan || !mode) {
            clearOverlay();
            return;
        }
        const leg = mode === 'optimize' ? plan.best : plan.current;
        void drawPath(
            leg.order
                .map((id) => stops.find((stop) => stop.id === id))
                .filter((stop): stop is MapStop => !!stop),
        );
        // drawPath reads the current map from a ref.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [state, plan, mode, stops]);

    const runPlan = async (next: 'route' | 'optimize') => {
        if (placedRoute.length < 2 || busy) return;
        setBusy(true);
        try {
            const matrix = await roadMatrix(
                placedRoute.map((stop) => stop.coordinates!),
            );
            const response = await sendJson(planRoute().url, 'POST', {
                visit_ids: placedRoute.map((stop) => stop.id),
                ...matrix,
            });
            if (!response.ok) throw new Error('plan');
            const result = (await response.json()) as Plan;
            setPlan(result);
            setMode(next);
        } catch {
            toast.error(t('map.plan_failed'));
        } finally {
            setBusy(false);
        }
    };

    const apply = () => {
        if (!plan) return;
        router.post(
            saveOrder().url,
            { visit_ids: plan.best.order },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setMode(null);
                    setPlan(null);
                },
            },
        );
    };

    const move = () => {
        if (!opened || moveTo === '') return;
        router.post(
            transferVisits().url,
            {
                visit_ids: [opened.id],
                from_user_id: opened.person_id,
                to_user_id: Number(moveTo),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpened(null);
                    setMoveTo('');
                    setPlan(null);
                    setMode(null);
                },
            },
        );
    };

    // Google Maps opens four stops on a phone: longer days continue from the previous destination.
    const addresses = (routable ? routeStops : stops)
        .map((stop) => stop.address)
        .filter((address): address is string => !!address);
    const googleRoutes: string[][] = [];
    for (let start = 0; start < addresses.length; start += 3) {
        const part = addresses.slice(start, start + 4);
        if (start === 0 || part.length > 1) googleRoutes.push(part);
    }

    const mapProblem =
        !key || state === 'rejected' || state === 'unavailable'
            ? t('map.unavailable')
            : null;
    const shownLeg =
        plan && mode ? (mode === 'optimize' ? plan.best : plan.current) : null;
    const nameOf = (id: number | null) =>
        id === null
            ? t('map.unassigned')
            : (people.find((p) => p.id === id)?.name ??
              stops.flatMap((stop) => stop.assignees).find((a) => a.id === id)
                  ?.name ??
              '');

    return (
        <>
            <Head title={t('map.title')} />
            <ScreenHeader
                title={t('map.title')}
                subtitle={
                    date === today ? t('map.today') : dayLabel(date, locale)
                }
            />

            <div className="space-y-3 p-4" data-test="map-page">
                {/* The day: ‹ Today › and a date picker. */}
                <div className="flex items-center gap-1">
                    <button
                        type="button"
                        className="da-press flex size-11 items-center justify-center rounded-xl"
                        aria-label={t('map.previous_day')}
                        onClick={() => go({ date: shiftDay(date, -1) })}
                    >
                        <ChevronLeft className="size-5" />
                    </button>
                    <label className="relative flex min-h-11 cursor-pointer items-center px-1 text-sm font-semibold whitespace-nowrap text-link underline decoration-dotted underline-offset-4">
                        <span data-test="map-date">
                            {date === today
                                ? t('map.today')
                                : dayLabel(date, locale)}
                        </span>
                        <input
                            type="date"
                            value={date}
                            aria-label={t('map.day')}
                            data-update-ignore
                            className="absolute inset-0 cursor-pointer opacity-0"
                            onClick={(e) => {
                                try {
                                    e.currentTarget.showPicker();
                                } catch {
                                    // Older browsers open it by themselves.
                                }
                            }}
                            onChange={(e) =>
                                e.target.value && go({ date: e.target.value })
                            }
                        />
                    </label>
                    <button
                        type="button"
                        className="da-press flex size-11 items-center justify-center rounded-xl"
                        aria-label={t('map.next_day')}
                        onClick={() => go({ date: shiftDay(date, 1) })}
                    >
                        <ChevronRight className="size-5" />
                    </button>
                    {date !== today && (
                        <Button
                            variant="tertiary"
                            size="sm"
                            onClick={() => go({ date: today })}
                        >
                            {t('map.today')}
                        </Button>
                    )}
                </div>

                {canManage && (
                    <FilterChips
                        label={t('map.person')}
                        selected={[person]}
                        onChange={(value) =>
                            router.get(
                                mapPage().url,
                                {
                                    date,
                                    ...(value !== 'all'
                                        ? { person: value }
                                        : {}),
                                },
                                { preserveScroll: true },
                            )
                        }
                        chips={[
                            { value: 'all', label: t('map.everyone') },
                            ...people.map((p) => ({
                                value: String(p.id),
                                label: p.name,
                            })),
                            { value: 'unassigned', label: t('map.unassigned') },
                        ]}
                    />
                )}

                {stops.length === 0 ? (
                    <p className="da-card p-4 text-sm text-muted-foreground">
                        {t('map.no_jobs')}
                    </p>
                ) : (
                    <>
                        {key && placed.length > 0 && state !== 'rejected' && (
                            <div className="relative">
                                <div
                                    ref={element}
                                    className="h-[52vh] min-h-72 w-full overflow-hidden rounded-[18px] shadow-[var(--shadow-card)] md:h-[32rem]"
                                    aria-label={t('map.title')}
                                    data-test="map-canvas"
                                />
                                {state === 'loading' && (
                                    <p className="absolute top-3 left-3 rounded-xl bg-background p-2 text-sm shadow">
                                        {t('map.loading')}
                                    </p>
                                )}
                            </div>
                        )}
                        {mapProblem && (
                            <p
                                role="status"
                                className="rounded-2xl bg-amber-50 p-3 text-sm text-amber-900"
                            >
                                {mapProblem}
                            </p>
                        )}
                        {placed.length < stops.length && (
                            <p className="text-sm text-muted-foreground">
                                {t('map.missing', {
                                    count: stops.length - placed.length,
                                })}
                            </p>
                        )}

                        {/* Route · Optimize · Open in Google Maps */}
                        <div
                            className="grid grid-cols-2 gap-2"
                            data-test="map-actions"
                        >
                            <Button
                                variant="outline"
                                disabled={!routable || placedRoute.length < 2}
                                loading={busy && mode !== 'optimize'}
                                onClick={() => void runPlan('route')}
                                data-test="map-route"
                            >
                                <RouteIcon />
                                {t('map.route')}
                            </Button>
                            <Button
                                variant="secondary"
                                disabled={!routable || placedRoute.length < 2}
                                onClick={() => void runPlan('optimize')}
                                data-test="map-optimize"
                            >
                                <Sparkles />
                                {t('map.optimize')}
                            </Button>
                            {googleRoutes.map((route, index) => (
                                <Button
                                    key={index}
                                    asChild
                                    variant="outline"
                                    className="col-span-2"
                                >
                                    <a
                                        href={routeUrl(route)}
                                        target="_blank"
                                        rel="noreferrer"
                                        data-test="map-google"
                                    >
                                        <ExternalLink />
                                        {googleRoutes.length > 1
                                            ? t('map.open_google_maps_part', {
                                                  part: index + 1,
                                                  parts: googleRoutes.length,
                                              })
                                            : t('map.open_google_maps')}
                                    </a>
                                </Button>
                            ))}
                        </div>
                        {!routable && (
                            <p className="text-xs text-muted-foreground">
                                {t('map.pick_person')}
                            </p>
                        )}
                        {routable && placedRoute.length < 2 && (
                            <p className="text-xs text-muted-foreground">
                                {t('map.need_two')}
                            </p>
                        )}

                        {plan && shownLeg && (
                            <section
                                className="da-card space-y-2 p-4"
                                data-test="map-plan"
                            >
                                {mode === 'optimize' && (
                                    <p
                                        className="font-semibold"
                                        data-test="map-saving"
                                    >
                                        {plan.changed
                                            ? t('map.saves', {
                                                  minutes: Math.max(
                                                      plan.saved_minutes,
                                                      plan.saved_drive_minutes,
                                                  ),
                                              })
                                            : t('map.best_already')}
                                    </p>
                                )}
                                {mode === 'optimize' &&
                                    plan.changed &&
                                    plan.saved_drive_minutes > 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            {t('map.saves_drive', {
                                                minutes:
                                                    plan.saved_drive_minutes,
                                                distance: distance(
                                                    Math.max(0, plan.saved_km),
                                                ),
                                            })}
                                        </p>
                                    )}
                                <p
                                    className="text-sm font-medium"
                                    data-test="map-totals"
                                >
                                    {t('map.route_summary', {
                                        time: duration(shownLeg.drive_minutes),
                                        distance: distance(
                                            shownLeg.distance_km,
                                        ),
                                    })}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('map.route_finish', {
                                        time: clock.clock(
                                            toHhmm(shownLeg.finish),
                                        ),
                                    })}
                                </p>
                                {shownLeg.late_minutes > 0 && (
                                    <p className="text-sm text-[var(--danger)]">
                                        {t('map.late', {
                                            minutes: shownLeg.late_minutes,
                                        })}
                                    </p>
                                )}
                                {plan.estimated && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('map.estimated')}
                                    </p>
                                )}
                                {mode === 'optimize' && plan.changed && (
                                    <div className="grid grid-cols-2 gap-2 pt-1">
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setMode(null);
                                                setPlan(null);
                                                clearOverlay();
                                            }}
                                        >
                                            {t('map.cancel')}
                                        </Button>
                                        <Button
                                            onClick={apply}
                                            data-test="map-apply"
                                        >
                                            {t('map.apply')}
                                        </Button>
                                    </div>
                                )}
                            </section>
                        )}

                        {mode === 'optimize' && plan?.changed && (
                            <h2 className="pt-1 text-sm font-semibold">
                                {t('map.new_order')}
                            </h2>
                        )}
                        <ol className="space-y-2" data-test="map-list">
                            {shownOrder.map((stop) => (
                                <li key={stop.id}>
                                    <button
                                        type="button"
                                        className={cn(
                                            'da-card flex w-full items-start gap-3 p-3 text-left',
                                            !open(stop) &&
                                                'opacity-60 grayscale',
                                        )}
                                        onClick={() => setOpened(stop)}
                                        data-test={`map-stop-${stop.id}`}
                                    >
                                        <span
                                            className="da-map-pin shrink-0"
                                            style={
                                                {
                                                    '--pin': `var(--status-${statusLook(stop.job_status).tone}-text)`,
                                                } as CSSProperties
                                            }
                                        >
                                            {stop.position}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-semibold">
                                                {clock.range(
                                                    stop.start_time,
                                                    stop.end_time,
                                                )}{' '}
                                                · {stop.customer}
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground">
                                                {stop.address}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {nameOf(stop.person_id)}
                                                {!stop.coordinates &&
                                                    ` · ${t('map.not_on_map')}`}
                                            </span>
                                        </span>
                                        <StatusBadge
                                            status={stop.job_status}
                                            label={stop.job_status_label}
                                        />
                                    </button>
                                </li>
                            ))}
                        </ol>
                    </>
                )}
            </div>

            {/* The pin's card. */}
            <Sheet
                open={opened !== null}
                onOpenChange={(value) => {
                    if (!value) {
                        setOpened(null);
                        setMoveTo('');
                    }
                }}
            >
                <SheetContent
                    side="bottom"
                    className="gap-3 px-4 pt-4"
                    data-test="map-card"
                >
                    {opened && (
                        <>
                            <SheetHeader className="p-0 pr-12">
                                <SheetTitle>
                                    {t('map.stop', {
                                        number: opened.position,
                                    })}{' '}
                                    · {opened.customer}
                                </SheetTitle>
                                <SheetDescription>
                                    {opened.address ?? t('map.not_on_map')}
                                </SheetDescription>
                            </SheetHeader>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                                <dt className="text-muted-foreground">
                                    {t('map.window')}
                                </dt>
                                <dd className="font-medium">
                                    {clock.range(
                                        opened.start_time,
                                        opened.end_time,
                                    )}
                                </dd>
                                <dt className="text-muted-foreground">
                                    {t('map.technician')}
                                </dt>
                                <dd className="font-medium">
                                    {opened.assignees
                                        .map((a) => a.name)
                                        .join(', ') || t('map.unassigned')}
                                </dd>
                                <dt className="text-muted-foreground">
                                    {t('map.status')}
                                </dt>
                                <dd>
                                    <StatusBadge
                                        status={opened.job_status}
                                        label={opened.job_status_label}
                                    />
                                </dd>
                            </dl>
                            <div className="grid grid-cols-3 gap-2">
                                {opened.address ? (
                                    <Button asChild variant="secondary">
                                        <a
                                            href={navigateUrl(opened.address)}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <Navigation />
                                            {t('map.navigate')}
                                        </a>
                                    </Button>
                                ) : (
                                    <Button variant="secondary" disabled>
                                        <Navigation />
                                        {t('map.navigate')}
                                    </Button>
                                )}
                                {opened.phone ? (
                                    <Button asChild variant="outline">
                                        <a href={`tel:${opened.phone}`}>
                                            <Phone />
                                            {t('map.call')}
                                        </a>
                                    </Button>
                                ) : (
                                    <Button variant="outline" disabled>
                                        <Phone />
                                        {t('map.call')}
                                    </Button>
                                )}
                                <Button asChild>
                                    <Link
                                        href={showJob(opened.job_id).url}
                                        data-test="map-open-job"
                                    >
                                        {t('map.open_job')}
                                    </Link>
                                </Button>
                            </div>
                            {canManage &&
                                open(opened) &&
                                !auth.company?.works_alone && (
                                    <div className="space-y-2 border-t pt-3">
                                        <label
                                            htmlFor="map-move-to"
                                            className="text-sm font-medium"
                                        >
                                            {t('map.move_to')}
                                        </label>
                                        <div className="flex gap-2">
                                            <NativeSelect
                                                id="map-move-to"
                                                data-update-ignore
                                                className="flex-1"
                                                value={moveTo}
                                                onChange={(e) =>
                                                    setMoveTo(e.target.value)
                                                }
                                            >
                                                <option value="">—</option>
                                                {people
                                                    .filter(
                                                        (p) =>
                                                            p.assignable !==
                                                                false &&
                                                            p.id !==
                                                                opened.person_id,
                                                    )
                                                    .map((p) => (
                                                        <option
                                                            key={p.id}
                                                            value={p.id}
                                                        >
                                                            {p.name}
                                                        </option>
                                                    ))}
                                            </NativeSelect>
                                            <Button
                                                disabled={moveTo === ''}
                                                onClick={move}
                                                data-test="map-move"
                                            >
                                                {t('map.move')}
                                            </Button>
                                        </div>
                                    </div>
                                )}
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}
