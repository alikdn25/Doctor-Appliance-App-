/** The CSRF token Laravel keeps in the XSRF-TOKEN cookie, for requests sent without Inertia. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * A JSON request in the background (autosave). With keepalive it still reaches the server while the page is
 * being left.
 */
export async function sendJson(
    url: string,
    method: 'POST' | 'PUT' | 'PATCH',
    data: unknown,
    { keepalive = false }: { keepalive?: boolean } = {},
): Promise<Response> {
    return fetch(url, {
        method,
        body: JSON.stringify(data),
        credentials: 'same-origin',
        keepalive,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });
}
