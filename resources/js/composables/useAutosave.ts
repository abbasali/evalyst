import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { safeStorage, sendJson } from '@/lib/http';
import { save as saveRoute } from '@/routes/student/answers';

export type AnswerPayload = {
    selected_option_ids: number[];
    text_answer: string;
    code_answer: string;
    flagged: boolean;
};

type Stored = { payload: AnswerPayload; editedAt: number };

export type SaveStatus =
    | 'idle'
    | 'pending'
    | 'saving'
    | 'saved'
    | 'offline'
    | 'error';

type Autosave = {
    status: Ref<SaveStatus>;
    queue: (
        position: number,
        payload: AnswerPayload,
        immediate?: boolean,
    ) => void;
    flush: () => Promise<boolean>;
    restoreUnsent: (
        isNewer: (position: number, editedAt: number) => boolean,
    ) => void;
    unsent: (position: number) => Stored | null;
    discard: (position: number) => void;
};

const DEBOUNCE_MS = 1500;

/** One queue per attempt, shared by every page of the attempt, so no stale copy keeps retrying. */
const instances = new Map<string, Autosave>();

/**
 * Debounced autosave for one attempt. Unsent answers are mirrored in localStorage (with the
 * time they were edited) so they survive a reload or a dropped connection.
 */
export function useAutosave(attemptId: string): Autosave {
    let instance = instances.get(attemptId);

    if (!instance) {
        instance = createAutosave(attemptId);
        instances.set(attemptId, instance);
    }

    return instance;
}

function createAutosave(attemptId: string): Autosave {
    const status = ref<SaveStatus>('idle');
    const prefix = `evalyst:attempt:${attemptId}:`;

    let pending = new Map<number, AnswerPayload>();
    let timer: ReturnType<typeof setTimeout> | null = null;
    let retryDelay = 1000;
    let inFlight: Promise<boolean> | null = null;
    let stopped = false;

    function queue(
        position: number,
        payload: AnswerPayload,
        immediate = false,
    ) {
        if (stopped) {
            return;
        }

        pending.set(position, payload);
        safeStorage.set(
            prefix + position,
            JSON.stringify({ payload, editedAt: Date.now() } satisfies Stored),
        );
        status.value = 'pending';
        schedule(immediate ? 0 : DEBOUNCE_MS);
    }

    function schedule(delay: number) {
        if (timer) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => void flush(), delay);
    }

    /** The attempt is over or continues elsewhere: stop saving and send the student on. */
    function stop(message: string | null, redirect: string | null) {
        stopped = true;
        pending.clear();

        if (timer) {
            clearTimeout(timer);
        }

        for (const key of safeStorage.keys(prefix)) {
            safeStorage.remove(key);
        }

        instances.delete(attemptId);

        if (message) {
            toast.error(message);
        }

        if (redirect) {
            router.visit(redirect);
        } else {
            router.reload();
        }
    }

    /** Send everything pending. Resolves true once nothing is left unsaved. */
    async function flush(): Promise<boolean> {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }

        while (inFlight) {
            await inFlight;
        }

        if (stopped || pending.size === 0) {
            return !stopped && status.value !== 'offline';
        }

        const batch = [...pending];
        pending = new Map();
        status.value = 'saving';

        inFlight = (async () => {
            for (const [index, [position, payload]] of batch.entries()) {
                let response: Response;

                try {
                    response = await sendJson(
                        'PUT',
                        saveRoute.url([attemptId, position]),
                        payload,
                    );
                } catch {
                    response = new Response(null, { status: 0 });
                }

                if ([401, 403, 409, 419].includes(response.status)) {
                    const data = (await response.json().catch(() => ({}))) as {
                        reason?: string;
                        redirect?: string;
                    };
                    stop(
                        {
                            expired: 'Time is up. Your quiz was submitted.',
                            closed: 'That question is closed.',
                            elsewhere:
                                'This quiz is continuing in another browser.',
                            session:
                                'Your session ended. Enter your code again.',
                            reset: 'Your instructor reset your attempt. You can start again.',
                        }[data.reason ?? ''] ?? 'Please reload the page.',
                        data.redirect ?? null,
                    );

                    return false;
                }

                if (response.status === 422) {
                    // Invalid for this question; drop it rather than retry forever.
                    safeStorage.remove(prefix + position);
                    status.value = 'error';
                    continue;
                }

                if (!response.ok) {
                    // Put back everything not sent yet, unless newer edits replaced it.
                    for (const [laterPosition, laterPayload] of batch.slice(
                        index,
                    )) {
                        if (!pending.has(laterPosition)) {
                            pending.set(laterPosition, laterPayload);
                        }
                    }

                    status.value = 'offline';
                    schedule(retryDelay);
                    retryDelay = Math.min(retryDelay * 2, 30000);

                    return false;
                }

                // Only clear the stored copy if nothing newer was typed meanwhile.
                if (!pending.has(position)) {
                    safeStorage.remove(prefix + position);
                }
            }

            retryDelay = 1000;

            if (status.value !== 'error') {
                status.value = pending.size ? 'pending' : 'saved';
            }

            return pending.size === 0;
        })();

        const ok = await inFlight;
        inFlight = null;

        return ok && pending.size === 0;
    }

    function unsent(position: number): Stored | null {
        try {
            return JSON.parse(
                safeStorage.get(prefix + position) ?? 'null',
            ) as Stored | null;
        } catch {
            return null;
        }
    }

    function discard(position: number) {
        pending.delete(position);
        safeStorage.remove(prefix + position);
    }

    /** Re-send answers left in localStorage by an earlier page, if still newer than the server's. */
    function restoreUnsent(
        isNewer: (position: number, editedAt: number) => boolean,
    ) {
        for (const key of safeStorage.keys(prefix)) {
            const position = Number(key.slice(prefix.length));
            const stored = unsent(position);

            if (stored && isNewer(position, stored.editedAt)) {
                pending.set(position, stored.payload);
            } else {
                safeStorage.remove(key);
            }
        }

        if (pending.size) {
            void flush();
        }
    }

    window.addEventListener('online', () => void flush());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            void flush();
        }
    });

    return { status, queue, flush, restoreUnsent, unsent, discard };
}
