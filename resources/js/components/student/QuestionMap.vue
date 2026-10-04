<script setup lang="ts">
import { Flag } from '@lucide/vue';
import { cn } from '@/lib/utils';

export type MapEntry = {
    position: number;
    answered: boolean;
    flagged: boolean;
};

/**
 * Numbered grid of questions: current, answered, flagged or unanswered.
 */
defineProps<{
    entries: MapEntry[];
    current?: number;
    /** Positions that can't be visited (one-way navigation). */
    isDisabled?: (position: number) => boolean;
}>();
const emit = defineEmits<{ go: [position: number] }>();
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-[repeat(auto-fill,2.5rem)] gap-1.5">
            <button
                v-for="entry in entries"
                :key="entry.position"
                type="button"
                :disabled="isDisabled?.(entry.position) ?? false"
                :aria-current="entry.position === current ? 'step' : undefined"
                :aria-label="`Question ${entry.position}${entry.answered ? ', answered' : ', not answered'}${entry.flagged ? ', flagged' : ''}`"
                :class="
                    cn(
                        'relative flex size-10 items-center justify-center rounded-md border text-sm font-medium tabular-nums transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                        entry.answered
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-background text-muted-foreground hover:bg-muted',
                        entry.position === current &&
                            'ring-2 ring-sky-500 ring-offset-2 ring-offset-background',
                    )
                "
                @click="emit('go', entry.position)"
            >
                {{ entry.position }}
                <Flag
                    v-if="entry.flagged"
                    class="absolute -top-1 -right-1 size-3.5 fill-amber-400 text-amber-500"
                />
            </button>
        </div>
        <div
            class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1">
                <span class="size-2.5 rounded-sm bg-primary" />
                Answered
            </span>
            <span class="flex items-center gap-1">
                <span class="size-2.5 rounded-sm border bg-background" />
                Not answered
            </span>
            <span class="flex items-center gap-1">
                <Flag class="size-3 fill-amber-400 text-amber-500" /> Flagged
            </span>
        </div>
    </div>
</template>
