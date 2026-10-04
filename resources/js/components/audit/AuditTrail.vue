<script setup lang="ts">
import { History } from '@lucide/vue';
import { formatInCourseTz } from '@/lib/datetime';
import { auditActionLabel } from '@/lib/grading';
import type { AuditEntry } from '@/types';

defineProps<{ entries: AuditEntry[]; title?: string }>();

function describe(values: Record<string, unknown> | null): string {
    if (!values) {
        return '';
    }

    return Object.entries(values)
        .filter(([key]) => key !== 'feedback')
        .map(([key, value]) => `${key.replaceAll('_', ' ')} ${value ?? '—'}`)
        .join(', ');
}
</script>

<template>
    <section class="space-y-2">
        <h3
            class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
        >
            <History class="size-3.5" /> {{ title ?? 'History' }}
        </h3>
        <p v-if="entries.length === 0" class="text-sm text-muted-foreground">
            No manual changes yet.
        </p>
        <ol v-else class="space-y-2 border-l pl-3">
            <li v-for="entry in entries" :key="entry.id" class="text-sm">
                <p>
                    <span class="font-medium">{{
                        entry.user ?? 'Someone'
                    }}</span>
                    · {{ auditActionLabel(entry.action) }}
                </p>
                <p
                    v-if="entry.before || entry.after"
                    class="text-xs text-muted-foreground"
                >
                    <span v-if="entry.before">{{
                        describe(entry.before)
                    }}</span>
                    <span v-if="entry.before && entry.after"> → </span>
                    <span v-if="entry.after">{{ describe(entry.after) }}</span>
                </p>
                <p
                    v-if="entry.note"
                    class="text-xs text-muted-foreground italic"
                >
                    “{{ entry.note }}”
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ formatInCourseTz(entry.created_at) }}
                </p>
            </li>
        </ol>
    </section>
</template>
