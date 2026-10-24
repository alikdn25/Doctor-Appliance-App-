import { usePage } from '@inertiajs/react';
import { Navigation } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { CalendarVisit, Lane } from '@/components/calendar/types';
import { isFinishedJob } from '@/components/jobs/types';
import { cn } from '@/lib/utils';
import { isDraftJob } from '@/lib/status';
import { DraftChip } from '@/components/ui/status-badge';
import { dayOrder, inLane, routeUrl } from '@/components/calendar/types';
import { Button } from '@/components/ui/button';
import { FilterChips } from '@/components/ui/filter-chips';
import {
    googleMapsKeyRejected,
    loadGoogleMapsLibrary,
    onGoogleMapsKeyRejected,
} from '@/lib/google-maps';
import { useClock } from '@/lib/datetime';
import { useTrans } from '@/lib/i18n';

type Position = { lat: number; lng: number };
type MapInstance = {
    fitBounds: (
        bounds: { north: number; south: number; east: number; west: number },
        padding: number,
    ) => void;
};
type MapsLibrary = {
    Map: new (
        element: HTMLElement,
        options: { center: Position; zoom: number; mapId: string },
    ) => MapInstance;
};
type Marker = { map: MapInstance | null };
type MarkerLibrary = {
    AdvancedMarkerElement: new (options: {
        map: MapInstance;
        position: Position;
        title: string;
        content: HTMLElement;
    }) => Marker;
};

/** Ordered visits, including addresses that cannot yet be placed on the map. */
export function DayMap({
    visits,
    lanes,
    onOpen,
}: {
    visits: CalendarVisit[];
    lanes: Lane[];
    onOpen: (visit: CalendarVisit) => void;
}) {
    const t = useTrans();
    const clock = useClock();
    const { auth } = usePage().props;
    const key = auth.company?.google_maps_key;
    const mapId = auth.company?.google_maps_map_id ?? 'DEMO_MAP_ID';
    const element = useRef<HTMLDivElement>(null);
    const [person, setPerson] = useState('all');
    const [state, setState] = useState<
        'loading' | 'ready' | 'unavailable' | 'rejected'
    >(googleMapsKeyRejected() ? 'rejected' : 'loading');
    useEffect(() => onGoogleMapsKeyRejected(() => setState('rejected')), []);
    const stops = useMemo(() => {
        const lane = lanes.find((item) => String(item.id) === person);
        const shown = visits.filter(
            (visit) => person === 'all' || (lane && inLane(visit, lane)),
        );

        // One person's day follows the order saved on the Map tab.
        return person === 'all'
            ? shown.sort(
                  (a, b) => a.start_minutes - b.start_minutes || a.id - b.id,
              )
            : dayOrder(shown);
    }, [visits, lanes, person]);
    const placed = stops.filter((visit) => visit.job.coordinates !== null);
    // Server configuration details are only for the platform admin who can fix them.
    const isAdmin = auth.user?.is_super_admin === true;
    const mapProblem = !key
        ? t(isAdmin ? 'calendar.map_no_key_admin' : 'calendar.map_unavailable')
        : state === 'rejected'
          ? t(
                isAdmin
                    ? 'calendar.map_key_rejected_admin'
                    : 'calendar.map_unavailable',
            )
          : state === 'unavailable'
            ? t('calendar.map_unavailable')
            : placed.length === 0
              ? t(
                    isAdmin
                        ? 'calendar.map_no_positions_admin'
                        : 'calendar.map_no_positions',
                )
              : null;
    const addresses = stops
        .map((visit) => visit.job.address)
        .filter((address): address is string => !!address);
    // Four stops work in mobile browsers too. Continue longer routes from the previous destination.
    const routes: string[][] = [];
    for (let start = 0; start < addresses.length; start += 3) {
        const part = addresses.slice(start, start + 4);
        if (start === 0 || part.length > 1) routes.push(part);
    }

    useEffect(() => {
        if (!key || !element.current) return;
        let cancelled = false;
        const markers: Marker[] = [];
        const container = element.current;
        setState('loading');
        void Promise.all([
            loadGoogleMapsLibrary<MapsLibrary>(key, 'maps'),
            loadGoogleMapsLibrary<MarkerLibrary>(key, 'marker'),
        ])
            .then(([maps, markerLibrary]) => {
                if (cancelled) return;
                const locations = stops.filter(
                    (visit) => visit.job.coordinates !== null,
                );
                if (!locations.length) return;
                const map = new maps.Map(container, {
                    center: locations[0].job.coordinates!,
                    zoom: 13,
                    mapId,
                });
                const points: Position[] = [];
                stops.forEach((visit, index) => {
                    const position = visit.job.coordinates;
                    if (!position) return;
                    points.push(position);
                    const label = document.createElement('button');
                    label.type = 'button';
                    label.className =
                        'flex size-9 items-center justify-center rounded-full border-2 border-white bg-blue-700 font-bold text-white shadow-md';
                    label.textContent = String(index + 1);
                    const title = `${index + 1}. ${clock.clock(visit.start_time)} ${visit.job.customer ?? ''} · ${visit.job.address ?? ''}`;
                    label.setAttribute('aria-label', title);
                    label.addEventListener('click', () => onOpen(visit));
                    markers.push(
                        new markerLibrary.AdvancedMarkerElement({
                            map,
                            position,
                            title,
                            content: label,
                        }),
                    );
                });
                if (points.length > 1)
                    map.fitBounds(
                        {
                            north: Math.max(
                                ...points.map((point) => point.lat),
                            ),
                            south: Math.min(
                                ...points.map((point) => point.lat),
                            ),
                            east: Math.max(...points.map((point) => point.lng)),
                            west: Math.min(...points.map((point) => point.lng)),
                        },
                        48,
                    );
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
            container.replaceChildren();
        };
    }, [key, mapId, stops, onOpen]);

    return (
        <section className="da-card space-y-3 p-3">
            <FilterChips
                label={t('calendar.map_person')}
                selected={[person]}
                onChange={setPerson}
                chips={[
                    { value: 'all', label: t('calendar.all_people') },
                    ...lanes.map((lane) => ({
                        value: String(lane.id),
                        label: lane.name ?? t('calendar.unassigned'),
                    })),
                ]}
            />
            {stops.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('calendar.no_visits')}
                </p>
            ) : (
                <>
                    {key && placed.length > 0 && state !== 'rejected' && (
                        <div className="relative">
                            <div
                                ref={element}
                                className="h-80 w-full rounded-lg sm:h-[28rem]"
                                aria-label={t('calendar.map')}
                            />
                            {state === 'loading' && (
                                <p className="absolute top-3 left-3 rounded bg-background p-2 text-sm">
                                    {t('calendar.map_loading')}
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
                            {t('calendar.map_missing', {
                                count: stops.length - placed.length,
                            })}
                        </p>
                    )}
                    <ol className="space-y-2">
                        {stops.map((visit, index) => (
                            <li key={visit.id}>
                                <button
                                    type="button"
                                    className={cn(
                                        'flex w-full items-start gap-3 rounded-lg border p-3 text-left hover:bg-muted/50',
                                        isFinishedJob(visit.job.status) &&
                                            'opacity-50 grayscale',
                                    )}
                                    onClick={() => onOpen(visit)}
                                >
                                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm text-primary-foreground">
                                        {index + 1}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold">
                                            {clock.range(
                                                visit.start_time,
                                                visit.end_time,
                                            )}{' '}
                                            · {visit.job.customer}
                                            {isDraftJob(visit.job.status) && (
                                                <DraftChip className="ml-1.5 align-middle" />
                                            )}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {visit.job.address}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {visit.assignees
                                                .map(
                                                    (assignee) => assignee.name,
                                                )
                                                .join(', ') ||
                                                t('calendar.unassigned')}
                                        </span>
                                        {!visit.job.coordinates && (
                                            <span className="text-xs text-muted-foreground">
                                                {t('calendar.not_on_map')}
                                            </span>
                                        )}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                    <div className="flex flex-wrap gap-2">
                        {routes.map((route, index) => (
                            <Button key={index} asChild variant="outline">
                                <a
                                    href={routeUrl(route)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Navigation />
                                    {t('calendar.open_route')}
                                    {routes.length > 1
                                        ? ` (${index + 1}/${routes.length})`
                                        : ''}
                                </a>
                            </Button>
                        ))}
                    </div>
                </>
            )}
        </section>
    );
}
