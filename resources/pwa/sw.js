/*
 * Service worker: makes the app installable and keeps it usable on weak signal.
 * Served by Laravel at /sw.js (ServiceWorkerController), which writes the build version below. A new deploy gives a
 * new file, so the phone installs the new worker, which takes over at once and deletes every older cache.
 *
 * - Built assets (/build/*, hashed file names) and icons: cache first.
 * - Pages: always from the network and never cached (they hold customer data). Whatever the server answers
 *   (a page, a redirect to /login, a 403, a 500, maintenance) is shown as is. The "No connection" page appears only
 *   when the phone has no network (navigator.onLine is false) or the request itself failed.
 * - Uploads and other non-GET requests are never touched: the in-page upload queue retries them.
 * Full offline work is out of scope (SPEC §7.4 for now).
 */
const VERSION = '__SW_VERSION__';
const STATIC_CACHE = `static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    // The new worker replaces the old one without waiting for every tab to close.
    self.skipWaiting();
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png']))
            // A failed pre-cache must not keep an old worker running: the offline page has a built-in fallback.
            .catch(() => undefined),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== STATIC_CACHE)
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'version' && event.ports && event.ports[0]) {
        event.ports[0].postMessage(VERSION);
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/icons/')
    ) {
        event.respondWith(cacheFirst(request));

        return;
    }

    // Full page loads only; Inertia visits (X-Inertia) are left to the browser and the page shows their errors.
    if (request.mode === 'navigate') {
        event.respondWith(navigate(request));
    }
});

async function cacheFirst(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    // Only a plain 200 for the very file asked for is kept: never a redirect or an error.
    if (response.ok && !response.redirected && response.type === 'basic') {
        const copy = response.clone();
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.put(request, copy))
            .catch(() => undefined);
    }

    return response;
}

async function navigate(request) {
    if (self.navigator.onLine === false) {
        return offlinePage();
    }

    let response;

    try {
        response = await fetch(request.url, {
            method: 'GET',
            headers: request.headers,
            credentials: 'include',
            redirect: 'follow',
            cache: 'no-store',
        });
    } catch {
        // A redirect to another site cannot be followed from here; let the browser follow it itself.
        try {
            return await fetch(request);
        } catch {
            return offlinePage();
        }
    }

    // The browser refuses a followed redirect as the answer to a page load, so it is told to go to the final
    // address itself (e.g. /my-jobs → /login). That address is fetched again, never taken from a cache.
    if (response.redirected) {
        return Response.redirect(response.url, 303);
    }

    // Any answer from the server, 4xx and 5xx included, is shown as is.
    return response;
}

async function offlinePage() {
    const cached = await caches.match(OFFLINE_URL);

    if (cached) {
        return cached;
    }

    return new Response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
            '<title>No connection</title><body style="font-family:system-ui,sans-serif;padding:24px;text-align:center">' +
            '<h1>No connection</h1><p>This page needs the internet.</p>' +
            '<button type="button" onclick="location.reload()" style="height:48px;width:100%;max-width:22rem">Try again</button>',
        {
            status: 503,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
        },
    );
}

// Push notifications (Settings → Notifications): show them, and open the app on tap.
self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Notification', {
            body: data.body || '',
            tag: data.tag || undefined,
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            data: { url: data.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(
        (event.notification.data && event.notification.data.url) || '/',
        self.location.origin,
    );

    // Only this app's own pages are opened.
    if (url.origin !== self.location.origin) {
        return;
    }

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((windows) => {
                for (const client of windows) {
                    if ('focus' in client) {
                        client.navigate(url.href);

                        return client.focus();
                    }
                }

                return self.clients.openWindow(url.href);
            }),
    );
});
