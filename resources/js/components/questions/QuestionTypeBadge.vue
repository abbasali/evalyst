<script setup lang="ts">
import { CircleDot, Code2, ListChecks, TextCursorInput } from '@lucide/vue';
import { computed } from 'vue';
import type { QuestionType } from '@/types';

const props = defineProps<{ type: QuestionType; label?: string }>();

const meta = computed(
    () =>
        ({
            single_choice: {
                icon: CircleDot,
                label: 'Single choice',
                tone: 'text-sky-700 bg-sky-50 dark:text-sky-300 dark:bg-sky-950',
            },
            multiple_choice: {
                icon: ListChecks,
                label: 'Multiple choice',
                tone: 'text-violet-700 bg-violet-50 dark:text-violet-300 dark:bg-violet-950',
            },
            open_text: {
                icon: TextCursorInput,
                label: 'Open text',
                tone: 'text-amber-700 bg-amber-50 dark:text-amber-300 dark:bg-amber-950',
            },
            open_code: {
                icon: Code2,
                label: 'Text + code',
                tone: 'text-emerald-700 bg-emerald-50 dark:text-emerald-300 dark:bg-emerald-950',
            },
        })[props.type],
);
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium whitespace-nowrap',
            meta.tone,
        ]"
    >
        <component :is="meta.icon" class="size-3.5" />
        {{ label ?? meta.label }}
    </span>
</template>
