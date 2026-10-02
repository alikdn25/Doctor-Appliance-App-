import type { Visit } from '@/components/jobs/types';

export type CalendarVisit = Visit & {
    start_minutes: number;
    end_minutes: number;
    on_site_minutes: number;
    assignee_ids: number[];
    conflict: boolean;
    movable: boolean;
    job: {
        id: number;
        number: number;
        status: string;
        status_label: string;
        job_type_label: string;
        customer: string | null;
        address: string | null;
        appliances: string[];
    };
};

export type Lane = {
    id: number | null;
    name: string | null;
    inactive: boolean;
};

export type UnscheduledJob = {
    id: number;
    number: number;
    status: string;
    status_label: string;
    job_type_label: string;
    customer: string | null;
    address: string | null;
    appliances: string[];
};

/** What is being dragged: an existing visit (from a lane) or a job waiting to be scheduled. */
export type DragItem =
    | {
          kind: 'visit';
          visit: CalendarVisit;
          fromLane: number | null;
          /** Minutes between the visit start and the point where it was grabbed. */
          grabOffset: number;
      }
    | { kind: 'job'; job: UnscheduledJob };

/** Visits of a lane: assigned to that person, or to nobody for the "unassigned" lane. */
export const inLane = (visit: CalendarVisit, lane: Lane) =>
    lane.id === null
        ? visit.assignee_ids.length === 0
        : visit.assignee_ids.includes(lane.id);

export const toTime = (minutes: number) => {
    const m = Math.max(0, Math.min(minutes, 24 * 60 - 15));

    return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
};

/** "Tue, Oct 6" for a "2026-10-06" calendar date (no timezone shift). */
export const dayLabel = (
    date: string,
    options: Intl.DateTimeFormatOptions = {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    },
) =>
    new Intl.DateTimeFormat('en-CA', { timeZone: 'UTC', ...options }).format(
        new Date(`${date}T12:00:00Z`),
    );

/**
 * Google Maps directions through the day's addresses in order (no API key needed).
 * The route starts from the phone's current location.
 */
export const routeUrl = (addresses: string[]) => {
    const stops = addresses.slice(0, 10);
    const destination = stops[stops.length - 1];
    const params = new URLSearchParams({
        api: '1',
        travelmode: 'driving',
        destination,
    });

    if (stops.length > 1) {
        params.set('waypoints', stops.slice(0, -1).join('|'));
    }

    return `https://www.google.com/maps/dir/?${params}`;
};
