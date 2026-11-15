import { router } from '@inertiajs/react';
import { xsrfToken } from '@/lib/http';

/**
 * The action log for App review (owner's task of November 15, PR 5). It records only what people do — screens
 * (page names like "jobs/show"), time on them, taps on buttons (their data-test key), forms started / saved /
 * abandoned, "back", searches found / not found, errors seen — and the time and taps of the main flows. Never text,
 * names, phones, addresses or amounts: the server drops anything that is not a short technical key. Events are sent
 * in batches in the background, so the app never waits for them.
 */

type UsageEvent = {
    type: string;
    screen?: string | null;
    name?: string | null;
    value?: number | null;
    meta?: Record<string, boolean | number>;
    at: number;
};

type Flow = { name: string; started: number; taps: number; quiet: boolean };

/** Main flows: where they start and which save ends them. */
const FLOWS: {
    name: string;
    starts: (screen: string, path: string) => boolean;
    ends: (method: string, path: string) => boolean;
    /** Started by just opening a screen people often leave without acting: not counted as abandoned. */
    quiet?: boolean;
}[] = [
    {
        name: 'quick_booking',
        starts: (screen) => screen === 'jobs/quick-booking',
        ends: (method, path) => method === 'post' && path === '/jobs',
    },
    {
        name: 'booking',
        starts: (screen, path) =>
            screen === 'jobs/quick-book' &&
            !window.location.search.includes('quick=1') &&
            path.startsWith('/jobs/create'),
        ends: (method, path) => method === 'post' && path === '/jobs',
    },
    {
        name: 'estimate',
        starts: (_screen, path) => /^\/jobs\/\d+\/estimates\/create/.test(path),
        ends: (method, path) =>
            method === 'post' && /^\/jobs\/\d+\/estimates$/.test(path),
    },
    {
        name: 'invoice',
        starts: (_screen, path) =>
            /^\/jobs\/\d+\/invoices\/create/.test(path) ||
            path.startsWith('/invoices/create'),
        ends: (method, path) =>
            method === 'post' && /^\/invoices\/\d+\/(send|sms)$/.test(path),
    },
    {
        name: 'reschedule',
        starts: (screen) =>
            screen === 'calendar/index' || screen === 'jobs/show',
        ends: (method, path) =>
            method === 'put' &&
            (/^\/visits\/\d+(\/move)?$/.test(path) ||
                /^\/jobs\/\d+\/details$/.test(path)),
        quiet: true,
    },
];

const SEND_EVERY = 15000;
const RECENT = 20;

let started = false;
let screen: string | null = null;
let screenSince = 0;
let formOpen: string | null | false = false;
let formSaved = false;
let lastVisit: { method: string; path: string } | null = null;
let poppedBack = false;
const queue: UsageEvent[] = [];
const recent: UsageEvent[] = [];
const flows = new Map<string, Flow>();
const session = Math.random().toString(36).slice(2, 12);

/** Only short technical keys leave the browser (the server checks again). */
function key(value: string | null | undefined): string | null {
    if (!value || !/^[a-z][a-z0-9_.:/-]{0,79}$/i.test(value)) return null;

    return /\d{3,}/.test(value) ? null : value.toLowerCase();
}

export function track(
    event: Omit<UsageEvent, 'at' | 'screen'> & { screen?: string | null },
): void {
    if (!started) return;
    const item: UsageEvent = {
        ...event,
        screen: key(event.screen ?? screen),
        name: key(event.name ?? null),
        at: Date.now(),
    };
    queue.push(item);
    recent.push(item);
    if (recent.length > RECENT) recent.shift();
}

/** The person's last actions, sent with an "Inconvenient" note. */
export function recentActions(): UsageEvent[] {
    return [...recent];
}

export function currentScreen(): string | null {
    return screen;
}

/** A search and whether it found anything. */
export function trackSearch(name: string, found: boolean): void {
    track({ type: 'search', name, meta: { found } });
}

function flush(): void {
    if (queue.length === 0) return;
    const events = queue.splice(0, 100);
    void fetch('/usage/events', {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ events, session }),
    }).catch(() => undefined);
}

function leaveScreen(): void {
    if (screen === null) return;
    track({ type: 'screen_time', value: Date.now() - screenSince });
    if (formOpen !== false && !formSaved) {
        track({ type: 'form_abandon', name: formOpen });
    }
    formOpen = false;
    formSaved = false;
}

function enterScreen(component: string, path: string): void {
    screen = component;
    screenSince = Date.now();
    track({ type: 'screen_view' });
    if (poppedBack) {
        track({ type: 'back' });
        poppedBack = false;
    }

    // Customer search ends when a customer is opened.
    if (component === 'customers/show') endFlow('customer_search');

    for (const flow of FLOWS) {
        if (flow.starts(component, path) && !flows.has(flow.name)) {
            flows.set(flow.name, {
                name: flow.name,
                started: Date.now(),
                taps: 0,
                quiet: !!flow.quiet,
            });
        }
    }
}

export function startFlow(name: string): void {
    if (!flows.has(name)) {
        flows.set(name, { name, started: Date.now(), taps: 0, quiet: false });
    }
}

export function endFlow(name: string): void {
    const flow = flows.get(name);
    if (!flow) return;
    flows.delete(name);
    track({
        type: 'flow',
        name,
        value: Date.now() - flow.started,
        meta: { taps: flow.taps },
    });
}

/** Flows left for more than 30 minutes count as abandoned. */
function expireFlows(): void {
    const now = Date.now();
    for (const flow of flows.values()) {
        if (now - flow.started > 30 * 60 * 1000) {
            flows.delete(flow.name);
            if (!flow.quiet) {
                track({
                    type: 'flow_abandoned',
                    name: flow.name,
                    meta: { taps: flow.taps },
                });
            }
        }
    }
}

/**
 * Starts the log once, for signed-in people inside a company (not on public or sign-in pages).
 */
export function startUsageLog(): void {
    if (started || typeof window === 'undefined') return;

    router.on('navigate', (event) => {
        const page = (event as CustomEvent).detail?.page;
        const component: string = page?.component ?? '';
        const inCompany = Boolean(page?.props?.auth?.company);

        if (!started && inCompany) started = true;
        if (!started) return;
        if (
            !inCompany ||
            component.startsWith('public/') ||
            component.startsWith('auth/')
        ) {
            leaveScreen();
            screen = null;

            return;
        }

        leaveScreen();
        enterScreen(
            component,
            new URL(page.url, window.location.origin).pathname,
        );
    });

    router.on('start', (event) => {
        const visit = (event as CustomEvent).detail?.visit;
        lastVisit = visit
            ? {
                  method: String(visit.method).toLowerCase(),
                  path: new URL(String(visit.url), window.location.origin)
                      .pathname,
              }
            : null;
    });

    router.on('success', () => {
        if (!lastVisit || lastVisit.method === 'get') return;
        if (formOpen !== false && !formSaved) {
            formSaved = true;
            track({ type: 'form_save', name: formOpen });
        }
        for (const flow of FLOWS) {
            if (
                flows.has(flow.name) &&
                flow.ends(lastVisit.method, lastVisit.path)
            ) {
                endFlow(flow.name);
            }
        }
    });

    router.on('error', () => track({ type: 'error', name: 'validation' }));
    router.on('httpException', () => track({ type: 'error', name: 'server' }));
    router.on('networkError', () => track({ type: 'error', name: 'network' }));
    router.on('flash', (event) => {
        const toast = (event as CustomEvent).detail?.flash?.toast;
        if (toast?.type === 'error') track({ type: 'error', name: 'toast' });
    });

    window.addEventListener('popstate', () => {
        poppedBack = true;
    });

    document.addEventListener(
        'click',
        (event) => {
            const target = (event.target as Element | null)?.closest(
                'button, a, [role="button"], [role="radio"], [role="tab"], [role="switch"]',
            );
            if (!target || !started) return;
            for (const flow of flows.values()) flow.taps++;
            const name =
                target.getAttribute('data-test') ??
                target.closest('[data-test]')?.getAttribute('data-test');
            track({ type: 'tap', name: key(name) });
            // ← in the header counts as "back".
            if (name === 'back-button') track({ type: 'back' });
        },
        true,
    );

    document.addEventListener(
        'input',
        (event) => {
            if (!started || formOpen !== false) return;
            const form = (event.target as Element | null)?.closest('form');
            if (!form) return;
            formOpen = key(form.getAttribute('data-test')) ?? null;
            formSaved = false;
            track({ type: 'form_start', name: formOpen });
        },
        true,
    );

    window.setInterval(() => {
        expireFlows();
        flush();
    }, SEND_EVERY);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            if (screen !== null) {
                track({ type: 'screen_time', value: Date.now() - screenSince });
                screenSince = Date.now();
            }
            flush();
        }
    });
}

/** Errors people see in toasts (e.g. "Not sent"). */
export function trackError(name: string): void {
    track({ type: 'error', name });
}
