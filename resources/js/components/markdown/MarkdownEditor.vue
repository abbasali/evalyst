<script setup lang="ts">
import { ref } from 'vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        placeholder?: string;
        rows?: number;
        invalid?: boolean;
        readonly?: boolean;
    }>(),
    { rows: 6, invalid: false, readonly: false },
);

const model = defineModel<string>({ default: '' });
const tab = ref<'write' | 'preview'>('write');
</script>

<template>
    <div
        :class="
            cn(
                'overflow-hidden rounded-md border shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                props.invalid && 'border-destructive',
            )
        "
    >
        <div
            class="flex items-center justify-between border-b bg-muted/40 px-2 text-xs"
        >
            <div class="flex">
                <button
                    v-for="option in ['write', 'preview'] as const"
                    :key="option"
                    type="button"
                    :class="
                        cn(
                            '-mb-px border-b-2 px-3 py-2 font-medium capitalize transition-colors',
                            tab === option
                                ? 'border-foreground text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )
                    "
                    @click="tab = option"
                >
                    {{ option }}
                </button>
            </div>
            <span class="hidden text-muted-foreground sm:inline">
                Markdown · use ```php for code
            </span>
        </div>
        <Textarea
            v-show="tab === 'write'"
            :id="props.id"
            v-model="model"
            :name="props.name"
            :rows="props.rows"
            :placeholder="props.placeholder"
            :readonly="props.readonly"
            :aria-invalid="props.invalid || undefined"
            class="min-h-24 rounded-none border-0 font-mono text-sm shadow-none focus-visible:ring-0"
        />
        <div
            v-if="tab === 'preview'"
            class="min-h-24 px-3 py-2 text-sm"
        >
            <Markdown v-if="model.trim()" :source="model" />
            <p v-else class="text-muted-foreground">Nothing to preview.</p>
        </div>
    </div>
</template>
