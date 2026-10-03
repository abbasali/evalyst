<script setup lang="ts">
import { ArrowDown, ArrowUp, Check, Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { QuestionOption } from '@/types';

const props = defineProps<{
    multiple: boolean;
    disabled?: boolean;
    errors: Record<string, string | undefined>;
}>();

const options = defineModel<QuestionOption[]>({ required: true });

function toggleCorrect(index: number) {
    if (props.disabled) {
        return;
    }

    options.value = options.value.map((option, i) => ({
        ...option,
        is_correct: props.multiple
            ? i === index
                ? !option.is_correct
                : option.is_correct
            : i === index,
    }));
}

function move(index: number, delta: number) {
    const next = [...options.value];
    const [item] = next.splice(index, 1);
    next.splice(index + delta, 0, item);
    options.value = next;
}

function remove(index: number) {
    options.value = options.value.filter((_, i) => i !== index);
}

function add() {
    options.value = [...options.value, { body: '', is_correct: false }];
}
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="(option, index) in options"
            :key="index"
            class="group flex items-start gap-2"
        >
            <button
                type="button"
                :disabled="disabled"
                :title="
                    option.is_correct ? 'Correct answer' : 'Mark as correct'
                "
                :aria-pressed="option.is_correct"
                :class="
                    cn(
                        'mt-1.5 flex size-6 shrink-0 items-center justify-center border transition-colors',
                        multiple ? 'rounded-md' : 'rounded-full',
                        option.is_correct
                            ? 'border-emerald-600 bg-emerald-600 text-white'
                            : 'text-transparent hover:border-emerald-600 hover:text-emerald-600/50',
                    )
                "
                @click="toggleCorrect(index)"
            >
                <Check class="size-3.5" />
            </button>
            <div class="min-w-0 flex-1">
                <Textarea
                    v-model="option.body"
                    rows="1"
                    :readonly="disabled"
                    :placeholder="`Option ${String.fromCharCode(65 + index)}`"
                    :aria-invalid="
                        !!errors[`options.${index}.body`] || undefined
                    "
                    class="min-h-9 text-sm"
                />
                <InputError :message="errors[`options.${index}.body`]" />
            </div>
            <div
                v-if="!disabled"
                class="mt-1 flex shrink-0 opacity-60 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
            >
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    :disabled="index === 0"
                    aria-label="Move up"
                    @click="move(index, -1)"
                >
                    <ArrowUp />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    :disabled="index === options.length - 1"
                    aria-label="Move down"
                    @click="move(index, 1)"
                >
                    <ArrowDown />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-7"
                    :disabled="options.length <= 2"
                    aria-label="Remove option"
                    @click="remove(index)"
                >
                    <Trash2 />
                </Button>
            </div>
        </div>

        <div class="flex items-center justify-between pl-8">
            <Button
                v-if="!disabled"
                type="button"
                variant="ghost"
                size="sm"
                :disabled="options.length >= 8"
                @click="add"
            >
                <Plus /> Add option
            </Button>
            <p class="text-xs text-muted-foreground">
                {{
                    multiple
                        ? 'Tick every correct option (at least two).'
                        : 'Tick the one correct option.'
                }}
            </p>
        </div>
        <InputError :message="errors.options" />
    </div>
</template>
