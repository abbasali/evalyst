<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { cn } from '@/lib/utils';
import type { QuestionOption, QuestionType } from '@/types';

/**
 * Renders a question the way students see it.
 * - preview: read-only inputs; `showAnswers` highlights the key
 * - answer: (M06) interactive inputs bound to the student's answer
 * - review: (M08) the student's answer next to the key
 */
const props = withDefaults(
    defineProps<{
        type: QuestionType;
        body: string;
        options?: QuestionOption[];
        codeLanguage?: string | null;
        mode?: 'preview' | 'answer' | 'review';
        showAnswers?: boolean;
    }>(),
    {
        options: () => [],
        codeLanguage: null,
        mode: 'preview',
        showAnswers: false,
    },
);

const letter = (index: number) => String.fromCharCode(65 + index);
</script>

<template>
    <div class="space-y-5">
        <Markdown :source="props.body" class="text-base" />

        <p
            v-if="props.type === 'multiple_choice'"
            class="text-xs font-medium text-muted-foreground"
        >
            Select all that apply.
        </p>

        <div
            v-if="
                props.type === 'single_choice' ||
                props.type === 'multiple_choice'
            "
            class="grid gap-2"
        >
            <div
                v-for="(option, index) in props.options"
                :key="option.id ?? index"
                :class="
                    cn(
                        'flex items-start gap-3 rounded-lg border px-3 py-2.5',
                        props.showAnswers &&
                            option.is_correct &&
                            'border-emerald-500/60 bg-emerald-50 dark:bg-emerald-950/40',
                    )
                "
            >
                <span
                    :class="
                        cn(
                            'mt-0.5 flex size-5 shrink-0 items-center justify-center border text-[10px] font-semibold text-muted-foreground',
                            props.type === 'single_choice'
                                ? 'rounded-full'
                                : 'rounded',
                        )
                    "
                >
                    {{ letter(index) }}
                </span>
                <Markdown :source="option.body" class="min-w-0 flex-1" />
                <template v-if="props.showAnswers">
                    <Check
                        v-if="option.is_correct"
                        class="size-4 shrink-0 text-emerald-600"
                    />
                    <X
                        v-else
                        class="size-4 shrink-0 text-muted-foreground/40"
                    />
                </template>
            </div>
        </div>

        <div v-else class="space-y-3">
            <div
                class="min-h-24 rounded-lg border border-dashed bg-muted/30 p-3 text-sm text-muted-foreground"
            >
                Students type their answer here.
            </div>
            <div
                v-if="props.type === 'open_code'"
                class="min-h-28 rounded-lg border border-dashed bg-muted/30 p-3 font-mono text-xs text-muted-foreground"
            >
                // {{ props.codeLanguage ?? 'code' }} editor
            </div>
        </div>
    </div>
</template>
