import { usePage } from '@inertiajs/vue3';

/**
 * Format an ISO date-time in the current course's timezone (all times are stored in UTC).
 */
export function formatInCourseTz(
    iso: string | null | undefined,
    options: Intl.DateTimeFormatOptions = {
        dateStyle: 'medium',
        timeStyle: 'short',
    },
    timezone?: string,
): string {
    if (!iso) {
        return '—';
    }

    const timeZone =
        timezone ?? usePage().props.currentTeam?.timezone ?? undefined;

    return new Intl.DateTimeFormat(undefined, { ...options, timeZone }).format(
        new Date(iso),
    );
}
