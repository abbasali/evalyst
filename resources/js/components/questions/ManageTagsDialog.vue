<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Pencil, Trash2, X } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useCourse } from '@/composables/useCourse';
import { destroy, update } from '@/routes/tags';
import type { TagSummary } from '@/types';

defineProps<{ tags: TagSummary[] }>();
const open = defineModel<boolean>('open', { required: true });

const { slug } = useCourse();
const editingId = ref<number | null>(null);
const name = ref('');
const error = ref<string | null>(null);

function startEdit(tag: TagSummary) {
    editingId.value = tag.id;
    name.value = tag.name;
    error.value = null;
}

function save(tag: TagSummary) {
    router.visit(update([slug.value, tag.id]), {
        data: { name: name.value },
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (editingId.value = null),
        onError: (errors) => (error.value = errors.name ?? null),
    });
}

function remove(tag: TagSummary) {
    if (
        !window.confirm(
            `Delete the tag “${tag.name}”? It is removed from ${tag.questions_count ?? 0} questions; the questions stay.`,
        )
    ) {
        return;
    }

    router.visit(destroy([slug.value, tag.id]), {
        preserveScroll: true,
        preserveState: true,
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Tags</DialogTitle>
                <DialogDescription>
                    Rename or delete tags. Deleting a tag keeps its questions.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="!tags.length"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                No tags yet. Add them while editing a question.
            </p>

            <ul
                v-else
                class="max-h-96 divide-y overflow-auto rounded-lg border"
            >
                <li
                    v-for="tag in tags"
                    :key="tag.id"
                    class="flex items-center gap-2 px-3 py-2 text-sm"
                >
                    <template v-if="editingId === tag.id">
                        <div class="flex-1">
                            <Input
                                v-model="name"
                                class="h-8"
                                maxlength="40"
                                v-focus
                                @keydown.enter.prevent="save(tag)"
                                @keydown.esc="editingId = null"
                            />
                            <p
                                v-if="error"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ error }}
                            </p>
                        </div>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            aria-label="Save"
                            @click="save(tag)"
                        >
                            <Check />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            aria-label="Cancel"
                            @click="editingId = null"
                        >
                            <X />
                        </Button>
                    </template>
                    <template v-else>
                        <span class="flex-1 truncate">{{ tag.name }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ tag.questions_count ?? 0 }} questions
                        </span>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :aria-label="`Rename ${tag.name}`"
                            @click="startEdit(tag)"
                        >
                            <Pencil />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :aria-label="`Delete ${tag.name}`"
                            @click="remove(tag)"
                        >
                            <Trash2 />
                        </Button>
                    </template>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
