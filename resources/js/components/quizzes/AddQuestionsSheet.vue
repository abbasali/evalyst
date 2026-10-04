<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { AlertTriangle, ChevronLeft, ChevronRight, Search } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { toast } from 'vue-sonner';
import { computed, reactive, ref, watch } from 'vue';
import QuestionTypeBadge from '@/components/questions/QuestionTypeBadge.vue';
import TagSelect from '@/components/questions/TagSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { markdownExcerpt } from '@/lib/markdown';
import { bank, store } from '@/routes/quizzes/questions';
import type { BankQuestion, Option, Paginated, TagSummary } from '@/types';

const props = defineProps<{
    quizId: number;
    types: Option[];
    difficulties: Option[];
    tags: TagSummary[];
}>();
const open = defineModel<boolean>('open', { required: true });

const { slug } = useCourse();
const http = useHttp<Record<string, never>, Paginated<BankQuestion>>();

const filters = reactive({
    q: '',
    type: null as string | null,
    difficulty: null as string | null,
    tags: [] as number[],
});
const page = ref(1);
const results = ref<Paginated<BankQuestion> | null>(null);
const failed = ref(false);
const loading = ref(false);

/** Selected questions, kept across pages and filter changes. */
const selected = ref(new Map<number, BankQuestion>());
const adding = ref(false);

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        results.value = await http.get(
            bank.url([slug.value, props.quizId], {
                query: {
                    q: filters.q || undefined,
                    type: filters.type || undefined,
                    difficulty: filters.difficulty || undefined,
                    tags: filters.tags.length ? filters.tags : undefined,
                    page: page.value,
                },
            }),
        );
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

const reload = useDebounceFn(() => {
    page.value = 1;
    load();
}, 300);

watch(filters, reload);
watch(open, (isOpen) => {
    if (isOpen) {
        selected.value = new Map();
        page.value = 1;
        load();
    }
});

function goTo(target: number) {
    page.value = target;
    load();
}

function toggle(question: BankQuestion, value: boolean | 'indeterminate') {
    const next = new Map(selected.value);

    if (value === true) {
        next.set(question.id, question);
    } else {
        next.delete(question.id);
    }

    selected.value = next;
}

const selectable = computed(
    () => results.value?.data.filter((question) => !question.added) ?? [],
);
const pageAllSelected = computed(
    () =>
        selectable.value.length > 0 &&
        selectable.value.every((question) => selected.value.has(question.id)),
);

function togglePage(value: boolean | 'indeterminate') {
    selectable.value.forEach((question) => toggle(question, value === true));
}

const unverified = computed(
    () =>
        [...selected.value.values()].filter(
            (question) => question.needs_verification,
        ).length,
);

function add() {
    router.post(
        store.url([slug.value, props.quizId]),
        { question_ids: [...selected.value.keys()] },
        {
            preserveScroll: true,
            onStart: () => (adding.value = true),
            onFinish: () => (adding.value = false),
            onSuccess: () => (open.value = false),
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        'Something changed. Reload and try again.',
                ),
        },
    );
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-2xl">
            <SheetHeader class="border-b">
                <SheetTitle>Add questions from the bank</SheetTitle>
                <SheetDescription>
                    Questions are added at the end with their default marks. You
                    can change marks and order afterwards.
                </SheetDescription>
            </SheetHeader>

            <div class="space-y-2 border-b p-4">
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.q"
                        type="search"
                        placeholder="Search question text"
                        class="pl-9"
                    />
                </div>
                <div class="grid gap-2 sm:grid-cols-[1fr_1fr_1.5fr]">
                    <NativeSelect v-model="filters.type">
                        <option :value="null">All types</option>
                        <option
                            v-for="type in types"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </NativeSelect>
                    <NativeSelect v-model="filters.difficulty">
                        <option :value="null">Any difficulty</option>
                        <option
                            v-for="difficulty in difficulties"
                            :key="difficulty.value"
                            :value="difficulty.value"
                        >
                            {{ difficulty.label }}
                        </option>
                    </NativeSelect>
                    <TagSelect
                        v-model="filters.tags"
                        :tags="tags"
                        :creatable="false"
                        placeholder="Filter by tags…"
                    />
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div
                    v-if="results && selectable.length"
                    class="flex items-center gap-3 border-b bg-muted/40 px-4 py-2 text-xs text-muted-foreground"
                >
                    <Checkbox
                        :model-value="pageAllSelected"
                        aria-label="Select all on this page"
                        @update:model-value="togglePage"
                    />
                    Select all on this page
                </div>

                <p
                    v-if="failed"
                    class="p-10 text-center text-sm text-muted-foreground"
                >
                    Couldn't load questions.
                    <Button variant="link" class="h-auto p-0" @click="load">
                        Try again
                    </Button>
                </p>
                <div
                    v-else-if="!results"
                    class="flex justify-center p-10 text-muted-foreground"
                >
                    <Spinner />
                </div>
                <p
                    v-else-if="results.data.length === 0"
                    class="p-10 text-center text-sm text-muted-foreground"
                >
                    No questions match these filters.
                </p>
                <ul v-else :class="['divide-y', loading && 'opacity-60']">
                    <li v-for="question in results.data" :key="question.id">
                        <label
                            :class="[
                                'flex items-start gap-3 px-4 py-3',
                                question.added
                                    ? 'cursor-default opacity-50'
                                    : 'cursor-pointer hover:bg-muted/30',
                            ]"
                        >
                            <Checkbox
                                class="mt-0.5"
                                :model-value="
                                    question.added || selected.has(question.id)
                                "
                                :disabled="question.added"
                                @update:model-value="
                                    (value) => toggle(question, value)
                                "
                            />
                            <span class="min-w-0 flex-1 space-y-1.5">
                                <span class="line-clamp-2 block text-sm">
                                    {{ markdownExcerpt(question.excerpt, 160) }}
                                </span>
                                <span
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <QuestionTypeBadge
                                        :type="question.type"
                                        :label="question.type_label"
                                    />
                                    <Badge
                                        variant="outline"
                                        class="font-normal"
                                    >
                                        {{ question.default_marks }}
                                        {{
                                            question.default_marks === 1
                                                ? 'mark'
                                                : 'marks'
                                        }}
                                    </Badge>
                                    <Badge
                                        v-if="question.difficulty"
                                        variant="outline"
                                        class="font-normal capitalize"
                                    >
                                        {{ question.difficulty }}
                                    </Badge>
                                    <Badge
                                        v-if="question.needs_verification"
                                        variant="warning"
                                    >
                                        <AlertTriangle /> Needs verification
                                    </Badge>
                                    <Badge
                                        v-for="tag in question.tags"
                                        :key="tag.id"
                                        variant="secondary"
                                        class="font-normal"
                                    >
                                        {{ tag.name }}
                                    </Badge>
                                    <span
                                        v-if="question.added"
                                        class="text-xs font-medium"
                                    >
                                        Already in this quiz
                                    </span>
                                </span>
                            </span>
                        </label>
                    </li>
                </ul>
            </div>

            <div
                v-if="results && results.last_page > 1"
                class="flex items-center justify-between border-t px-4 py-2 text-xs text-muted-foreground"
            >
                <span>
                    Page {{ results.current_page }} of {{ results.last_page }} ·
                    {{ results.total }} questions
                </span>
                <div class="flex gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        aria-label="Previous page"
                        :disabled="results.current_page <= 1 || loading"
                        @click="goTo(results.current_page - 1)"
                    >
                        <ChevronLeft />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        aria-label="Next page"
                        :disabled="
                            results.current_page >= results.last_page || loading
                        "
                        @click="goTo(results.current_page + 1)"
                    >
                        <ChevronRight />
                    </Button>
                </div>
            </div>

            <SheetFooter class="border-t">
                <p
                    v-if="unverified"
                    class="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400"
                >
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    {{ unverified }} selected
                    {{ unverified === 1 ? 'question needs' : 'questions need' }}
                    verification: the AI checker disagreed with the answer key.
                    Check {{ unverified === 1 ? 'it' : 'them' }} before
                    publishing.
                </p>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-muted-foreground">
                        {{ selected.size }} selected
                    </span>
                    <Button
                        :disabled="selected.size === 0 || adding"
                        @click="add"
                    >
                        <Spinner v-if="adding" />
                        {{
                            unverified
                                ? 'Add anyway'
                                : `Add ${selected.size || ''} to quiz`
                        }}
                    </Button>
                </div>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
