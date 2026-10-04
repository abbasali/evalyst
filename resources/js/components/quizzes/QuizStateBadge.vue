<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { QuizState } from '@/types';

const props = defineProps<{ state: QuizState }>();

const meta = computed(
    () =>
        ({
            draft: { label: 'Draft', variant: 'secondary' },
            upcoming: { label: 'Upcoming', variant: 'info' },
            open: { label: 'Open', variant: 'success' },
            closed: { label: 'Closed', variant: 'outline' },
            archived: { label: 'Archived', variant: 'outline' },
        })[props.state] as {
            label: string;
            variant: 'secondary' | 'info' | 'success' | 'outline';
        },
);
</script>

<template>
    <Badge :variant="meta.variant">
        <span
            v-if="state === 'open'"
            class="size-1.5 rounded-full bg-emerald-500"
            aria-hidden="true"
        />
        {{ meta.label }}
    </Badge>
</template>
