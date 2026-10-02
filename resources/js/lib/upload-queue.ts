/**
 * Upload queue for photos and signatures taken on weak signal (basements, laundry rooms).
 *
 * Files are saved in IndexedDB first, so nothing is lost if the upload fails, the tab is closed
 * or the phone restarts. The queue retries with growing pauses (2 s … 1 min), immediately when the
 * phone comes back online, and on the next app start. The server treats repeated uploads of the
 * same item as one (photos carry a client_uuid).
 */

export type UploadStatus = 'pending' | 'uploading' | 'failed';

export type UploadItem = {
    id: string;
    url: string;
    /** Field name of the file in the form. */
    fileField: string;
    fields: Record<string, string>;
    blob: Blob;
    filename: string;
    /** Groups items for display, e.g. "job:12:photo:before". */
    tag: string;
    status: UploadStatus;
    attempts: number;
    error?: string;
    createdAt: number;
};

type Listener = (items: UploadItem[]) => void;

const DB_NAME = 'field-service';
const STORE = 'uploads';
const MAX_DELAY = 60_000;

let memory: UploadItem[] = [];
let dbPromise: Promise<IDBDatabase | null> | null = null;
let running = false;
let timer: ReturnType<typeof setTimeout> | null = null;
const listeners = new Set<Listener>();
const doneListeners = new Set<(item: UploadItem) => void>();

function openDb(): Promise<IDBDatabase | null> {
    if (dbPromise) {
        return dbPromise;
    }

    dbPromise = new Promise((resolve) => {
        try {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => {
                request.result.createObjectStore(STORE, { keyPath: 'id' });
            };
            request.onsuccess = () => resolve(request.result);
            // Private browsing or blocked storage: keep the queue in memory only.
            request.onerror = () => resolve(null);
        } catch {
            resolve(null);
        }
    });

    return dbPromise;
}

async function tx<T>(
    mode: IDBTransactionMode,
    run: (store: IDBObjectStore) => IDBRequest<T> | void,
): Promise<T | undefined> {
    const db = await openDb();

    if (!db) {
        return undefined;
    }

    return new Promise((resolve) => {
        const transaction = db.transaction(STORE, mode);
        const request = run(transaction.objectStore(STORE));
        transaction.oncomplete = () =>
            resolve(request ? request.result : undefined);
        transaction.onerror = () => resolve(undefined);
    });
}

async function load(): Promise<void> {
    const stored = await tx<UploadItem[]>('readonly', (store) =>
        store.getAll(),
    );

    if (stored) {
        // Uploads cut off by closing the app go back to the queue.
        memory = stored
            .map((item) =>
                item.status === 'uploading'
                    ? { ...item, status: 'pending' as const }
                    : item,
            )
            .sort((a, b) => a.createdAt - b.createdAt);
    }

    notify();
}

async function save(item: UploadItem): Promise<void> {
    memory = [...memory.filter((i) => i.id !== item.id), item].sort(
        (a, b) => a.createdAt - b.createdAt,
    );
    notify();
    await tx('readwrite', (store) => store.put(item));
}

async function remove(id: string): Promise<void> {
    memory = memory.filter((i) => i.id !== id);
    notify();
    await tx('readwrite', (store) => store.delete(id));
}

function notify(): void {
    listeners.forEach((listener) => listener(memory));
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Errors that will not go away by trying again (bad file, no access, job deleted). */
const permanent = (status: number) =>
    [400, 401, 403, 404, 409, 413, 422].includes(status);

async function send(item: UploadItem): Promise<void> {
    const body = new FormData();
    Object.entries(item.fields).forEach(([key, value]) =>
        body.append(key, value),
    );
    body.append(item.fileField, item.blob, item.filename);

    await save({ ...item, status: 'uploading' });

    let response: Response;

    try {
        response = await fetch(item.url, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });
    } catch {
        // No signal: keep it and try again later.
        await save({ ...item, status: 'pending', attempts: item.attempts + 1 });

        return;
    }

    if (response.ok) {
        await remove(item.id);
        doneListeners.forEach((listener) => listener(item));

        return;
    }

    let message = `HTTP ${response.status}`;

    try {
        const json = await response.json();
        message =
            (json.errors && (Object.values(json.errors)[0] as string[])[0]) ||
            json.message ||
            message;
    } catch {
        // Not JSON (e.g. a proxy error page).
    }

    await save({
        ...item,
        status: permanent(response.status) ? 'failed' : 'pending',
        attempts: item.attempts + 1,
        error: message,
    });
}

function schedule(delay: number): void {
    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void process(), delay);
}

async function process(): Promise<void> {
    if (running) {
        return;
    }

    running = true;

    try {
        // `memory` is replaced (not mutated) on every change, so this loops over a snapshot.
        for (const item of memory) {
            if (item.status !== 'pending') {
                continue;
            }

            if (!navigator.onLine) {
                break;
            }

            await send(item);
        }
    } finally {
        running = false;
    }

    const waiting = memory.filter((i) => i.status === 'pending');

    if (waiting.length > 0) {
        const attempts = Math.min(...waiting.map((i) => i.attempts));
        schedule(Math.min(2000 * 2 ** attempts, MAX_DELAY));
    }
}

/** RFC 4122 v4 id (crypto.randomUUID needs HTTPS; this works everywhere). */
export function uuid(): string {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));

    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex.slice(6, 8).join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

let started = false;

/** Loads saved uploads and starts sending them. Safe to call more than once. */
export function startUploadQueue(): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;
    window.addEventListener('online', () => void process());
    void load().then(() => process());
}

export async function enqueueUpload(
    item: Omit<UploadItem, 'id' | 'status' | 'attempts' | 'createdAt'>,
): Promise<void> {
    startUploadQueue();
    await save({
        ...item,
        id: uuid(),
        status: 'pending',
        attempts: 0,
        createdAt: Date.now(),
    });
    void process();
}

export async function retryUpload(id: string): Promise<void> {
    const item = memory.find((i) => i.id === id);

    if (item) {
        await save({ ...item, status: 'pending', error: undefined });
        void process();
    }
}

export const discardUpload = (id: string) => remove(id);

export function subscribeUploads(listener: Listener): () => void {
    listeners.add(listener);
    listener(memory);

    return () => listeners.delete(listener);
}

export function onUploadDone(listener: (item: UploadItem) => void): () => void {
    doneListeners.add(listener);

    return () => doneListeners.delete(listener);
}
