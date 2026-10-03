declare global {
    interface Window {
        google?: { maps?: { importLibrary?: (name: string) => Promise<unknown> } };
        __doctorMapsReady?: () => void;
    }
}

let loading: Promise<void> | null = null;

/** Load one Maps script for both Places and the dispatch map, with a bounded failure time. */
export async function loadGoogleMapsLibrary<T>(key: string, library: string): Promise<T> {
    if (!window.google?.maps?.importLibrary) {
        loading ??= new Promise<void>((resolve, reject) => {
            const script = document.createElement('script');
            const fail = () => {
                window.clearTimeout(timer);
                script.remove();
                delete window.__doctorMapsReady;
                loading = null;
                reject(new Error('Google Maps is unavailable'));
            };
            const timer = window.setTimeout(fail, 15000);
            window.__doctorMapsReady = () => {
                window.clearTimeout(timer);
                delete window.__doctorMapsReady;
                resolve();
            };
            script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&loading=async&callback=__doctorMapsReady`;
            script.async = true;
            script.onerror = fail;
            document.head.append(script);
        });
        await loading;
    }
    const importer = window.google?.maps?.importLibrary;
    if (!importer) throw new Error('Google Maps is unavailable');
    return (await importer(library)) as T;
}
