<script setup lang="ts">
import { Clock } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { cn } from '@/lib/utils';

/**
 * Counts down to the server deadline. The offset between the server clock and this device's
 * clock is measured on load, so a wrong device clock doesn't matter.
 */
const props = defineProps<{ deadlineAt: string; serverNow: string }>();
const emit = defineEmits<{ expired: [] }>();

const offset = new Date(props.serverNow).getTime() - Date.now();
const deadline = new Date(props.deadlineAt).getTime();
const remaining = ref(compute());
const warned = {
    five: remaining.value <= 5 * 60_000,
    one: remaining.value <= 60_000,
};
// Announced once to screen readers (the ticking label itself is not announced).
const announcement = ref('');
let expired = false;
let interval: ReturnType<typeof setInterval> | undefined;

function compute(): number {
    return Math.max(0, deadline - (Date.now() + offset));
}

function tick() {
    remaining.value = compute();

    if (!warned.five && remaining.value <= 5 * 60_000) {
        warned.five = true;
        announcement.value = '5 minutes left.';
        toast.warning('5 minutes left.');
    }

    if (!warned.one && remaining.value <= 60_000) {
        warned.one = true;
        announcement.value = '1 minute left.';
        toast.error('1 minute left. Your answers are saved automatically.');
    }

    if (!expired && remaining.value === 0) {
        expired = true;
        emit('expired');
    }
}

const onVisible = () => document.visibilityState === 'visible' && tick();

onMounted(() => {
    tick();
    interval = setInterval(tick, 1000);
    document.addEventListener('visibilitychange', onVisible);
});

onBeforeUnmount(() => {
    clearInterval(interval);
    document.removeEventListener('visibilitychange', onVisible);
});

const label = computed(() => {
    const total = Math.ceil(remaining.value / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = String(total % 60).padStart(2, '0');

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${seconds}`
        : `${String(minutes).padStart(2, '0')}:${seconds}`;
});
</script>

<template>
    <div
        role="timer"
        :aria-label="`Time left ${label}`"
        :class="
            cn(
                'flex items-center gap-1.5 rounded-full border px-3 py-1 font-mono text-sm font-semibold tabular-nums transition-colors',
                remaining <= 60_000
                    ? 'animate-pulse border-red-500/50 bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'
                    : remaining <= 5 * 60_000
                      ? 'border-amber-500/50 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                      : 'bg-background',
            )
        "
    >
        <Clock class="size-3.5" />
        {{ label }}
        <span class="sr-only" aria-live="polite">{{ announcement }}</span>
    </div>
</template>
