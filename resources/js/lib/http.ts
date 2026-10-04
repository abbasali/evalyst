/**
 * Minimal JSON request for background calls (autosave, events) that must not trigger an
 * Inertia visit. Sends Laravel's XSRF cookie as the CSRF header.
 */
export async function sendJson(
    method: 'POST' | 'PUT',
    url: string,
    body: unknown,
): Promise<Response> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: csrfHeaders(),
        body: JSON.stringify(body),
    });
}

/** JSON headers plus Laravel's XSRF cookie as the CSRF header. */
export function csrfHeaders(): Record<string, string> {
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    };
}

/** localStorage that never throws (private mode, blocked storage). */
export const safeStorage = {
    get(key: string): string | null {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key: string, value: string): void {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // ignore
        }
    },
    remove(key: string): void {
        try {
            window.localStorage.removeItem(key);
        } catch {
            // ignore
        }
    },
    keys(prefix: string): string[] {
        try {
            return Object.keys(window.localStorage).filter((key) =>
                key.startsWith(prefix),
            );
        } catch {
            return [];
        }
    },
};
