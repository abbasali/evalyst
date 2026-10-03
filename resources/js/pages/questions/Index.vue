<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Copy,
    Eye,
    Library,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    Sparkles,
    Tags,
    Trash2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import ManageTagsDialog from '@/components/questions/ManageTagsDialog.vue';
import QuestionPreviewDialog from '@/components/questions/QuestionPreviewDialog.vue';
import QuestionTypeBadge from '@/components/questions/QuestionTypeBadge.vue';
import TagSelect from '@/components/questions/TagSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCourse } from '@/composables/useCourse';
import { markdownExcerpt } from '@/lib/markdown';
import {
    bulk,
    create,
    destroy,
    duplicate,
    edit,
    index,
    restore,
} from '@/routes/questions';
import type {
    Paginated,
    QuestionFormOptions,
    QuestionRow,
    Team,
} from '@/types';

type Filters = {
    q: string;
    type: string | null;
    difficulty: string | null;
    source: string | null;
    tags: number[];
    needs_verification: boolean;
    trashed: boolean;
};

const props = defineProps<
    QuestionFormOptions & {
        questions: Paginated<QuestionRow>;
        filters: Filters;
        total: number;
    }
>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            { title: 'Question bank', href: index(props.currentTeam.slug) },
        ],
    }),
});

const { slug } = useCourse();

const filters = reactive<Filters>({ ...props.filters });
const hasFilters = computed(
    () =>
        !!(
            filters.q ||
            filters.type ||
            filters.difficulty ||
            filters.source ||
            filters.tags.length ||
            filters.needs_verification ||
            filters.trashed
        ),
);

const applyFilters = useDebounceFn(() => {
    router.get(
        index.url(slug.value, {
            query: {
                q: filters.q || undefined,
                type: filters.type || undefined,
                difficulty: filters.difficulty || undefined,
                source: filters.source || undefined,
                tags: filters.tags.length ? filters.tags : undefined,
                needs_verification: filters.needs_verification ? 1 : undefined,
                trashed: filters.trashed ? 1 : undefined,
            },
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}, 300);

watch(filters, () => {
    selected.value = [];
    applyFilters();
});

function clearFilters() {
    Object.assign(filters, {
        q: '',
        type: null,
        difficulty: null,
        source: null,
        tags: [],
        needs_verification: false,
        trashed: false,
    });
}

// Selection & bulk actions
const selected = ref<number[]>([]);
const allSelected = computed(
    () =>
        props.questions.data.length > 0 &&
        props.questions.data.every((q) => selected.value.includes(q.id)),
);
const bulkTagId = ref<string>('');

function toggleAll(value: boolean | 'indeterminate') {
    selected.value =
        value === true ? props.questions.data.map((q) => q.id) : [];
}

function toggle(id: number, value: boolean | 'indeterminate') {
    selected.value =
        value === true
            ? [...selected.value, id]
            : selected.value.filter((selectedId) => selectedId !== id);
}

function runBulk(action: 'add_tag' | 'remove_tag' | 'delete') {
    if (
        action === 'delete' &&
        !window.confirm(`Delete ${selected.value.length} questions?`)
    ) {
        return;
    }

    router.post(
        bulk.url(slug.value),
        {
            action,
            ids: selected.value,
            tag_id: action === 'delete' ? null : Number(bulkTagId.value),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                bulkTagId.value = '';
            },
        },
    );
}

// Dialogs
const previewId = ref<number | null>(null);
const previewOpen = ref(false);
const tagsOpen = ref(false);
const deleting = ref<QuestionRow | null>(null);
const deleteOpen = ref(false);

function preview(question: QuestionRow) {
    previewId.value = question.id;
    previewOpen.value = true;
}

function confirmDelete(question: QuestionRow) {
    deleting.value = question;
    deleteOpen.value = true;
}

function remove() {
    if (!deleting.value) {
        return;
    }

    router.visit(destroy([slug.value, deleting.value.id]), {
        preserveScroll: true,
        onSuccess: () => (deleteOpen.value = false),
    });
}
</script>

<template>
    <Head title="Question bank" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Question bank"
            description="Every question in this course, shared by all instructors."
        >
            <template #actions>
                <Button variant="ghost" @click="tagsOpen = true">
                    <Tags /> Tags
                </Button>
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <span>
                                <Button variant="outline" disabled>
                                    <Sparkles /> Generate with AI
                                </Button>
                            </span>
                        </TooltipTrigger>
                        <TooltipContent
                            >Coming in the next milestone</TooltipContent
                        >
                    </Tooltip>
                </TooltipProvider>
                <Button as-child>
                    <Link :href="create(slug)"><Plus /> New question</Link>
                </Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="total === 0"
            :icon="Library"
            title="Your question bank is empty"
            description="Write questions by hand, or generate a batch with AI and keep the ones you like."
        >
            <Button as-child>
                <Link :href="create(slug)"><Plus /> New question</Link>
            </Button>
        </EmptyState>

        <template v-else>
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative w-full sm:w-72">
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
                    <NativeSelect v-model="filters.type" class="w-40">
                        <option :value="null">All types</option>
                        <option
                            v-for="t in types"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </NativeSelect>
                    <NativeSelect v-model="filters.difficulty" class="w-36">
                        <option :value="null">Any difficulty</option>
                        <option
                            v-for="d in difficulties"
                            :key="d.value"
                            :value="d.value"
                        >
                            {{ d.label }}
                        </option>
                    </NativeSelect>
                    <NativeSelect v-model="filters.source" class="w-36">
                        <option :value="null">Any source</option>
                        <option value="manual">Manual</option>
                        <option value="ai">AI generated</option>
                    </NativeSelect>
                    <div class="w-full sm:w-64">
                        <TagSelect
                            v-model="filters.tags"
                            :tags="tags"
                            placeholder="Filter by tags…"
                        />
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <Checkbox v-model="filters.needs_verification" />
                        Needs verification
                    </label>
                    <label class="flex items-center gap-2">
                        <Checkbox v-model="filters.trashed" />
                        Show deleted
                    </label>
                    <Button
                        v-if="hasFilters"
                        variant="link"
                        size="sm"
                        class="h-auto p-0"
                        @click="clearFilters"
                    >
                        Clear filters
                    </Button>
                </div>
            </div>

            <div
                v-if="selected.length"
                class="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
            >
                <span class="font-medium">{{ selected.length }} selected</span>
                <NativeSelect v-model="bulkTagId" class="w-44">
                    <option value="">Choose a tag…</option>
                    <option
                        v-for="tag in tags"
                        :key="tag.id"
                        :value="String(tag.id)"
                    >
                        {{ tag.name }}
                    </option>
                </NativeSelect>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!bulkTagId"
                    @click="runBulk('add_tag')"
                >
                    Add tag
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="!bulkTagId"
                    @click="runBulk('remove_tag')"
                >
                    Remove tag
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    class="text-destructive"
                    @click="runBulk('delete')"
                >
                    <Trash2 /> Delete
                </Button>
            </div>

            <div class="overflow-hidden rounded-xl border">
                <div
                    class="flex items-center gap-3 border-b bg-muted/50 px-4 py-2.5 text-xs text-muted-foreground"
                >
                    <Checkbox
                        :model-value="allSelected"
                        aria-label="Select all on this page"
                        @update:model-value="toggleAll"
                    />
                    <span>{{ questions.total }} questions</span>
                </div>

                <ul class="divide-y">
                    <li
                        v-for="question in questions.data"
                        :key="question.id"
                        :class="[
                            'flex items-start gap-3 px-4 py-3 transition-colors hover:bg-muted/30',
                            question.deleted && 'opacity-60',
                        ]"
                    >
                        <Checkbox
                            class="mt-1"
                            :model-value="selected.includes(question.id)"
                            :aria-label="`Select question ${question.id}`"
                            @update:model-value="
                                (value) => toggle(question.id, value)
                            "
                        />
                        <button
                            type="button"
                            class="min-w-0 flex-1 space-y-1.5 text-left"
                            @click="preview(question)"
                        >
                            <p class="line-clamp-2 text-sm">
                                {{ markdownExcerpt(question.excerpt, 160) }}
                            </p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <QuestionTypeBadge
                                    :type="question.type"
                                    :label="question.type_label"
                                />
                                <Badge variant="outline" class="font-normal">
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
                                    v-if="question.source === 'ai'"
                                    variant="info"
                                >
                                    <Sparkles /> AI
                                </Badge>
                                <Badge
                                    v-if="question.needs_verification"
                                    variant="warning"
                                >
                                    <AlertTriangle /> Verify
                                </Badge>
                                <Badge
                                    v-if="question.locked"
                                    variant="secondary"
                                >
                                    <Lock /> Answered
                                </Badge>
                                <Badge
                                    v-if="question.deleted"
                                    variant="destructive"
                                >
                                    Deleted
                                </Badge>
                                <span
                                    v-for="tag in question.tags"
                                    :key="tag.id"
                                    class="rounded bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground"
                                >
                                    {{ tag.name }}
                                </span>
                            </div>
                        </button>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 shrink-0"
                                    aria-label="Question actions"
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <template v-if="question.deleted">
                                    <DropdownMenuItem
                                        @click="
                                            router.post(
                                                restore.url([
                                                    slug,
                                                    question.id,
                                                ]),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        "
                                    >
                                        <RotateCcw /> Restore
                                    </DropdownMenuItem>
                                </template>
                                <template v-else>
                                    <DropdownMenuItem
                                        @click="preview(question)"
                                    >
                                        <Eye /> Preview
                                    </DropdownMenuItem>
                                    <DropdownMenuItem as-child>
                                        <Link :href="edit([slug, question.id])">
                                            <Pencil /> Edit
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @click="
                                            router.post(
                                                duplicate.url([
                                                    slug,
                                                    question.id,
                                                ]),
                                            )
                                        "
                                    >
                                        <Copy /> Duplicate
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        @click="confirmDelete(question)"
                                    >
                                        <Trash2 /> Delete
                                    </DropdownMenuItem>
                                </template>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </li>

                    <li
                        v-if="questions.data.length === 0"
                        class="px-4 py-12 text-center text-sm text-muted-foreground"
                    >
                        No questions match these filters.
                        <Button
                            variant="link"
                            class="h-auto p-0"
                            @click="clearFilters"
                        >
                            Clear filters
                        </Button>
                    </li>
                </ul>
            </div>

            <Pagination :paginator="questions" noun="questions" />
        </template>
    </div>

    <QuestionPreviewDialog
        v-model:open="previewOpen"
        :question-id="previewId"
    />
    <ManageTagsDialog v-model:open="tagsOpen" :tags="tags" />
    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Delete question?"
        description="It disappears from the bank and the quiz builder. Quizzes that already use it are not affected, and you can restore it later."
        confirm-label="Delete"
        destructive
        @confirm="remove"
    />
</template>
