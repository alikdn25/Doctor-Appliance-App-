/*
 * Service worker: makes the app installable and keeps it usable on weak signal.
 * - Built assets (/build/*, hashed file names) and icons: cache first.
 * - Pages: always from the network (they hold customer data); a small offline page when there is none.
 * - Uploads and other non-GET requests are never touched: the in-page upload queue retries them.
 * Full offline work is out of scope (SPEC §7.4 for now).
 */
const VERSION = 'v1';
const STATIC_CACHE = `static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png']))
            .then(() => self.skipWaiting()),
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
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches
                                .open(STATIC_CACHE)
                                .then((cache) => cache.put(request, copy));
                        }

                        return response;
                    }),
            ),
        );

        return;
    }

    // Full page loads only; Inertia visits (X-Inertia) fail normally and the page shows the error.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
    }
});
