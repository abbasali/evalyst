<script setup lang="ts">
import { defineAsyncComponent } from 'vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { QuestionType } from '@/types';

const CodeEditor = defineAsyncComponent({
    loader: () => import('@/components/code/CodeEditor.vue'),
    loadingComponent: Spinner,
});

export type StudentQuestion = {
    /** Position in the attempt (student) or assessment question ID (preview). */
    key: number;
    type: QuestionType;
    body: string;
    code_language: string | null;
    options: { id: number; body: string }[];
};

export type AnswerValue = {
    selected_option_ids: number[];
    text_answer: string;
    code_answer: string;
};

/**
 * A question with the input for its type. Question text can't be selected or copied.
 */
const props = defineProps<{ question: StudentQuestion }>();
const answer = defineModel<AnswerValue>({ required: true });
const emit = defineEmits<{ paste: [length: number] }>();

const letter = (index: number) => String.fromCharCode(65 + index);
const TEXT_MAX = 10000;

function choose(id: number) {
    if (props.question.type === 'single_choice') {
        answer.value = { ...answer.value, selected_option_ids: [id] };

        return;
    }

    const selected = answer.value.selected_option_ids;
    answer.value = {
        ...answer.value,
        selected_option_ids: selected.includes(id)
            ? selected.filter((selectedId) => selectedId !== id)
            : [...selected, id],
    };
}

function onPaste(event: ClipboardEvent) {
    emit('paste', event.clipboardData?.getData('text').length ?? 0);
}

const blockCopy = (event: Event) => event.preventDefault();
</script>

<template>
    <div class="space-y-6">
        <div
            class="select-none"
            @copy="blockCopy"
            @cut="blockCopy"
            @contextmenu="blockCopy"
            @dragstart="blockCopy"
        >
            <Markdown :source="question.body" class="text-base" />
        </div>

        <fieldset
            v-if="
                question.type === 'single_choice' ||
                question.type === 'multiple_choice'
            "
            class="space-y-2"
        >
            <legend class="mb-2 text-xs font-medium text-muted-foreground">
                {{
                    question.type === 'single_choice'
                        ? 'Choose one answer.'
                        : 'Select all that apply.'
                }}
            </legend>
            <label
                v-for="(option, index) in question.options"
                :key="option.id"
                :class="
                    cn(
                        'flex cursor-pointer items-start gap-3 rounded-lg border bg-background px-4 py-3 transition-colors select-none hover:bg-muted/50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                        answer.selected_option_ids.includes(option.id) &&
                            'border-primary bg-primary/5 ring-1 ring-primary hover:bg-primary/5',
                    )
                "
                @copy="blockCopy"
                @contextmenu="blockCopy"
            >
                <input
                    :type="
                        question.type === 'single_choice' ? 'radio' : 'checkbox'
                    "
                    class="sr-only"
                    :name="`question-${question.key}`"
                    :checked="answer.selected_option_ids.includes(option.id)"
                    @change="choose(option.id)"
                />
                <span
                    :class="
                        cn(
                            'mt-0.5 flex size-6 shrink-0 items-center justify-center border text-xs font-semibold',
                            question.type === 'single_choice'
                                ? 'rounded-full'
                                : 'rounded',
                            answer.selected_option_ids.includes(option.id)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground',
                        )
                    "
                >
                    {{ letter(index) }}
                </span>
                <Markdown :source="option.body" class="min-w-0 flex-1" />
            </label>
        </fieldset>

        <div v-else class="space-y-4">
            <div class="space-y-1.5">
                <label
                    :for="`text-${question.key}`"
                    class="text-sm font-medium"
                >
                    {{
                        question.type === 'open_code'
                            ? 'Explanation'
                            : 'Your answer'
                    }}
                </label>
                <Textarea
                    :id="`text-${question.key}`"
                    :model-value="answer.text_answer"
                    :maxlength="TEXT_MAX"
                    :rows="question.type === 'open_code' ? 4 : 8"
                    class="field-sizing-content min-h-28 bg-background"
                    :placeholder="
                        question.type === 'open_code'
                            ? 'Explain your approach (optional unless the question asks for it).'
                            : 'Type your answer…'
                    "
                    @update:model-value="
                        (value) =>
                            (answer = { ...answer, text_answer: String(value) })
                    "
                    @paste="onPaste"
                />
                <p
                    class="text-right text-xs text-muted-foreground tabular-nums"
                >
                    {{ answer.text_answer.length.toLocaleString() }} /
                    {{ TEXT_MAX.toLocaleString() }}
                </p>
            </div>

            <div v-if="question.type === 'open_code'" class="space-y-1.5">
                <p class="text-sm font-medium">
                    Code
                    <span
                        v-if="question.code_language"
                        class="font-normal text-muted-foreground"
                    >
                        ({{ question.code_language }})
                    </span>
                </p>
                <CodeEditor
                    :model-value="answer.code_answer"
                    :language="question.code_language"
                    aria-label="Code answer"
                    @update:model-value="
                        (value: string) =>
                            (answer = { ...answer, code_answer: value })
                    "
                    @paste="(length: number) => emit('paste', length)"
                />
            </div>
        </div>
    </div>
</template>
