import { onBeforeUnmount, onMounted } from 'vue';
import { csrfHeaders } from '@/lib/http';
import { store as eventsRoute } from '@/routes/student/events';

export type AttemptEvent = {
    type: 'focus_lost' | 'focus_returned' | 'pasted' | 'fullscreen_exited';
    position?: number;
    length?: number;
};

const FLUSH_EVERY_MS = 5000;

/**
 * Reports activity during an attempt in batches (at most one request every 5 seconds).
 * With `trackFocus`, leaving and returning to the tab is recorded too. Never blocks the student.
 */
export function useAttemptEvents(attemptId: string, trackFocus: boolean) {
    let queue: AttemptEvent[] = [];
    let timer: ReturnType<typeof setTimeout> | null = null;
    let away = false;

    function record(event: AttemptEvent) {
        queue.push(event);
        timer ??= setTimeout(flush, FLUSH_EVERY_MS);
    }

    function flush() {
        timer = null;

        if (queue.length === 0) {
            return;
        }

        const events = queue.slice(0, 20);
        queue = queue.slice(20);

        // keepalive lets the request finish while the page unloads (sendBeacon can't send CSRF headers).
        void fetch(eventsRoute.url(attemptId), {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: csrfHeaders(),
            body: JSON.stringify({ events }),
        }).catch(() => undefined);

        if (queue.length) {
            timer = setTimeout(flush, FLUSH_EVERY_MS);
        }
    }

    function left() {
        if (!away) {
            away = true;
            record({ type: 'focus_lost' });
        }
    }

    function returned() {
        if (
            away &&
            document.visibilityState === 'visible' &&
            document.hasFocus()
        ) {
            away = false;
            record({ type: 'focus_returned' });
        }
    }

    const onVisibility = () =>
        document.visibilityState === 'hidden' ? left() : returned();
    let blurTimer: ReturnType<typeof setTimeout> | null = null;
    // Ignore very short blurs (e.g. a browser permission prompt).
    const onBlur = () => (blurTimer = setTimeout(left, 2000));
    const onFocus = () => {
        if (blurTimer) {
            clearTimeout(blurTimer);
        }

        returned();
    };
    const onPageHide = () => flush();

    onMounted(() => {
        if (trackFocus) {
            document.addEventListener('visibilitychange', onVisibility);
            window.addEventListener('blur', onBlur);
            window.addEventListener('focus', onFocus);
        }

        window.addEventListener('pagehide', onPageHide);
    });

    onBeforeUnmount(() => {
        if (blurTimer) {
            clearTimeout(blurTimer);
        }

        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('blur', onBlur);
        window.removeEventListener('focus', onFocus);
        window.removeEventListener('pagehide', onPageHide);
        flush();
    });

    return { record };
}
