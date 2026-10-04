<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { computed } from 'vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { codeFence } from '@/lib/grading';
import { cn } from '@/lib/utils';
import type { AnswerOption } from '@/types';

const props = defineProps<{
    type: string;
    codeLanguage: string | null;
    options: AnswerOption[];
    textAnswer: string | null;
    codeAnswer: string | null;
}>();

const isChoice = computed(() => props.type.endsWith('_choice'));
const noAnswer = computed(() =>
    isChoice.value
        ? !props.options.some((option) => option.selected)
        : !props.textAnswer?.trim() && !props.codeAnswer?.trim(),
);
</script>

<template>
    <div class="space-y-3">
        <ul v-if="isChoice" class="space-y-1.5">
            <li
                v-for="(option, i) in options"
                :key="i"
                :class="
                    cn(
                        'flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm',
                        option.selected &&
                            option.correct === false &&
                            'border-destructive/40 bg-destructive/5',
                        option.correct &&
                            'border-emerald-500/40 bg-emerald-500/5',
                        option.selected &&
                            option.correct === null &&
                            'border-primary/40 bg-primary/5',
                    )
                "
            >
                <span
                    :class="
                        cn(
                            'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                            option.selected &&
                                'border-primary bg-primary text-primary-foreground',
                        )
                    "
                    aria-hidden="true"
                >
                    <Check v-if="option.selected" class="size-3" />
                </span>
                <Markdown :source="option.body" class="min-w-0 flex-1" />
                <span
                    v-if="option.correct"
                    class="shrink-0 text-xs font-medium text-emerald-700 dark:text-emerald-400"
                >
                    Correct
                </span>
                <X
                    v-else-if="option.selected && option.correct === false"
                    class="size-4 shrink-0 text-destructive"
                    aria-label="Wrong choice"
                />
                <span v-if="option.selected" class="sr-only">(selected)</span>
            </li>
        </ul>

        <template v-else>
            <p
                v-if="textAnswer?.trim()"
                class="rounded-lg border bg-muted/30 p-3 text-sm whitespace-pre-wrap"
            >
                {{ textAnswer }}
            </p>
            <Markdown
                v-if="codeAnswer?.trim()"
                :source="codeFence(codeAnswer, codeLanguage)"
            />
        </template>

        <p v-if="noAnswer" class="text-sm text-muted-foreground italic">
            No answer.
        </p>
    </div>
</template>
