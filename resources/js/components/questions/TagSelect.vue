<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { onClickOutside } from '@vueuse/core';
import { Plus, Tag as TagIcon, X } from '@lucide/vue';
import { computed, ref, useTemplateRef } from 'vue';
import { useCourse } from '@/composables/useCourse';
import { store as storeTag } from '@/routes/tags';
import type { TagSummary } from '@/types';

const props = withDefaults(
    defineProps<{ tags: TagSummary[]; max?: number; placeholder?: string }>(),
    { max: 10, placeholder: 'Add tags…' },
);
const selected = defineModel<number[]>({ required: true });

const { slug } = useCourse();
const available = ref<TagSummary[]>([...props.tags]);
const query = ref('');
const open = ref(false);
const root = useTemplateRef<HTMLElement>('root');
const http = useHttp({ name: '' });

onClickOutside(root, () => (open.value = false));

const selectedTags = computed(() =>
    selected.value
        .map((id) => available.value.find((tag) => tag.id === id))
        .filter((tag): tag is TagSummary => !!tag),
);

const suggestions = computed(() => {
    const term = query.value.trim().toLowerCase();

    return available.value
        .filter((tag) => !selected.value.includes(tag.id))
        .filter((tag) => !term || tag.name.toLowerCase().includes(term))
        .slice(0, 8);
});

const canCreate = computed(() => {
    const term = query.value.trim().toLowerCase();

    return (
        term.length > 0 &&
        term.length <= 40 &&
        !available.value.some((tag) => tag.name.toLowerCase() === term)
    );
});

function add(tag: TagSummary) {
    if (selected.value.length >= props.max || selected.value.includes(tag.id)) {
        return;
    }

    selected.value = [...selected.value, tag.id];
    query.value = '';
}

function remove(id: number) {
    selected.value = selected.value.filter((tagId) => tagId !== id);
}

async function create() {
    http.name = query.value.trim();
    const tag = (await http.post(storeTag.url(slug.value))) as TagSummary;

    if (!available.value.some((existing) => existing.id === tag.id)) {
        available.value.push(tag);
    }

    add(tag);
}

function onEnter() {
    if (suggestions.value[0]) {
        add(suggestions.value[0]);
    } else if (canCreate.value) {
        void create();
    }
}
</script>

<template>
    <div ref="root" class="relative">
        <div
            class="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border px-2 py-1.5 shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30"
            @click="open = true"
        >
            <span
                v-for="tag in selectedTags"
                :key="tag.id"
                class="inline-flex items-center gap-1 rounded bg-secondary px-1.5 py-0.5 text-xs"
            >
                {{ tag.name }}
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground"
                    :aria-label="`Remove ${tag.name}`"
                    @click.stop="remove(tag.id)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <input
                v-model="query"
                type="text"
                class="min-w-24 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                :placeholder="selectedTags.length ? '' : placeholder"
                :disabled="selected.length >= max"
                @focus="open = true"
                @keydown.enter.prevent="onEnter"
                @keydown.backspace="
                    !query &&
                    selected.length &&
                    remove(selected[selected.length - 1])
                "
                @keydown.esc="open = false"
            />
        </div>

        <div
            v-if="open && (suggestions.length || canCreate)"
            class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md border bg-popover p-1 text-sm shadow-md"
        >
            <button
                v-for="tag in suggestions"
                :key="tag.id"
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-accent"
                @click="add(tag)"
            >
                <TagIcon class="size-3.5 text-muted-foreground" />
                {{ tag.name }}
                <span
                    v-if="tag.questions_count !== undefined"
                    class="ml-auto text-xs text-muted-foreground"
                >
                    {{ tag.questions_count }}
                </span>
            </button>
            <button
                v-if="canCreate"
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-accent"
                :disabled="http.processing"
                @click="create"
            >
                <Plus class="size-3.5" /> Create “{{ query.trim() }}”
            </button>
        </div>
    </div>
</template>
