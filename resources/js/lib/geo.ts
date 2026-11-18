/**
 * The phone's position for "On my way" (drive time in the text). Never blocks the button for long: no answer in
 * a few seconds, no permission or no GPS gives null, and the text goes without the time.
 */
export function currentPosition(
    timeoutMs = 4000,
): Promise<{ lat: number; lng: number } | null> {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        const timer = window.setTimeout(() => resolve(null), timeoutMs + 500);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                window.clearTimeout(timer);
                resolve({
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                });
            },
            () => {
                window.clearTimeout(timer);
                resolve(null);
            },
            {
                enableHighAccuracy: false,
                timeout: timeoutMs,
                maximumAge: 120000,
            },
        );
    });
}
