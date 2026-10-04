<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';

const props = defineProps<{ status: string }>();

const meta = computed(
    () =>
        (
            ({
                ungraded: { label: 'Not graded', variant: 'outline' },
                pending: { label: 'AI grading', variant: 'info' },
                grading: { label: 'Grading', variant: 'info' },
                submitted: { label: 'Submitted', variant: 'info' },
                needs_review: { label: 'Needs review', variant: 'warning' },
                failed: { label: 'Failed', variant: 'destructive' },
                final: { label: 'Graded', variant: 'success' },
                graded: { label: 'Graded', variant: 'success' },
                in_progress: { label: 'In progress', variant: 'secondary' },
                not_started: { label: 'Not started', variant: 'outline' },
            }) as Record<
                string,
                {
                    label: string;
                    variant:
                        | 'outline'
                        | 'info'
                        | 'warning'
                        | 'destructive'
                        | 'success'
                        | 'secondary';
                }
            >
        )[props.status] ?? { label: props.status, variant: 'outline' },
);
</script>

<template>
    <Badge :variant="meta.variant">{{ meta.label }}</Badge>
</template>
