<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    Library,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/EmptyState.vue';
import QuestionPreviewDialog from '@/components/questions/QuestionPreviewDialog.vue';
import QuestionTypeBadge from '@/components/questions/QuestionTypeBadge.vue';
import AddQuestionsSheet from '@/components/quizzes/AddQuestionsSheet.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCourse } from '@/composables/useCourse';
import { markdownExcerpt } from '@/lib/markdown';
import { index } from '@/routes/quizzes';
import { destroy, order, update } from '@/routes/quizzes/questions';
import type {
    Option,
    QuizQuestionItem,
    QuizShellProps,
    TagSummary,
    Team,
} from '@/types';

const props = defineProps<
    QuizShellProps & {
        items: QuizQuestionItem[];
        types: Option[];
        difficulties: Option[];
        tags: TagSummary[];
    }
>();

defineOptions({
    layout: (props: { currentTeam: Team } & QuizShellProps) => ({
        breadcrumbs: [
            { title: 'Quizzes', href: index(props.currentTeam.slug) },
            { title: props.quiz.title, href: '#' },
        ],
    }),
});

const { slug } = useCourse();

/** Local copy so reordering and marks feel instant; refreshed from the server. */
const rows = ref<QuizQuestionItem[]>([]);
const marks = ref<Record<number, string>>({});

watch(
    () => props.items,
    (items) => {
        rows.value = items.map((item) => ({ ...item }));
        marks.value = Object.fromEntries(
            items.map((item) => [item.id, String(item.marks)]),
        );
    },
    { immediate: true },
);

const total = () =>
    rows.value.reduce((sum, item) => sum + Number(item.marks), 0);

const pickerOpen = ref(false);

/** Show the first server error (e.g. the list changed in another tab). */
function toastFirstError(errors: Record<string, string>) {
    toast.error(Object.values(errors)[0] ?? 'Something went wrong.');
}

/** Marks values currently being saved, so Enter + blur don't send twice. */
const savingMarks = new Map<number, number>();
const previewId = ref<number | null>(null);
const previewOpen = ref(false);

function preview(item: QuizQuestionItem) {
    previewId.value = item.question.id;
    previewOpen.value = true;
}

function move(index: number, delta: -1 | 1) {
    const target = index + delta;

    if (target < 0 || target >= rows.value.length) {
        return;
    }

    const next = [...rows.value];
    [next[index], next[target]] = [next[target], next[index]];
    rows.value = next;

    router.patch(
        order.url([slug.value, props.quiz.id]),
        { ids: next.map((item) => item.id) },
        { preserveScroll: true, onError: toastFirstError },
    );
}

function saveMarks(item: QuizQuestionItem) {
    const value = Number(marks.value[item.id]);

    if (value === item.marks || savingMarks.get(item.id) === value) {
        return;
    }

    savingMarks.set(item.id, value);

    router.patch(
        update.url([slug.value, props.quiz.id, item.id]),
        { marks: value },
        {
            preserveScroll: true,
            // Don't let a reorder/remove click on another row cancel this save.
            async: true,
            onFinish: () => savingMarks.delete(item.id),
            onError: (errors) => {
                marks.value[item.id] = String(item.marks);
                toast.error(errors.marks ?? 'Could not update the marks.');
            },
        },
    );
}

function remove(item: QuizQuestionItem) {
    router.delete(destroy.url([slug.value, props.quiz.id, item.id]), {
        preserveScroll: true,
        onError: toastFirstError,
    });
}
</script>

<template>
    <Head :title="`${quiz.title} · Questions`" />

    <QuizShell :quiz="quiz" :checklist="checklist" tab="questions">
        <EmptyState
            v-if="rows.length === 0"
            :icon="Library"
            title="No questions yet"
            description="Pick questions from your question bank. You can set marks for each one and change the order."
        >
            <Button
                :disabled="!quiz.can.edit_questions"
                @click="pickerOpen = true"
            >
                <Plus /> Add questions
            </Button>
        </EmptyState>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    Students see the questions in this order unless shuffling is
                    on. Click a question to preview it.
                </p>
                <Button
                    v-if="quiz.can.edit_questions"
                    @click="pickerOpen = true"
                >
                    <Plus /> Add questions
                </Button>
            </div>

            <div class="overflow-hidden rounded-xl border">
                <ol class="divide-y">
                    <li
                        v-for="(item, index) in rows"
                        :key="item.id"
                        class="flex items-start gap-3 px-3 py-3 sm:px-4"
                    >
                        <div class="flex shrink-0 flex-col items-center">
                            <Button
                                v-if="quiz.can.edit_questions"
                                variant="ghost"
                                size="icon"
                                class="size-6"
                                :disabled="index === 0"
                                :aria-label="`Move question ${index + 1} up`"
                                @click="move(index, -1)"
                            >
                                <ArrowUp />
                            </Button>
                            <span
                                class="flex size-6 items-center justify-center text-sm font-medium text-muted-foreground tabular-nums"
                            >
                                {{ index + 1 }}
                            </span>
                            <Button
                                v-if="quiz.can.edit_questions"
                                variant="ghost"
                                size="icon"
                                class="size-6"
                                :disabled="index === rows.length - 1"
                                :aria-label="`Move question ${index + 1} down`"
                                @click="move(index, 1)"
                            >
                                <ArrowDown />
                            </Button>
                        </div>

                        <div
                            class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-start"
                        >
                            <button
                                type="button"
                                class="min-w-0 flex-1 space-y-1.5 pt-1 text-left"
                                @click="preview(item)"
                            >
                                <p class="line-clamp-2 text-sm">
                                    {{
                                        markdownExcerpt(
                                            item.question.excerpt,
                                            180,
                                        )
                                    }}
                                </p>
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <QuestionTypeBadge
                                        :type="item.question.type"
                                        :label="item.question.type_label"
                                    />
                                    <Badge
                                        v-if="item.question.needs_verification"
                                        variant="warning"
                                    >
                                        <AlertTriangle /> Needs verification
                                    </Badge>
                                    <Badge
                                        v-if="item.question.deleted"
                                        variant="destructive"
                                    >
                                        Deleted from bank
                                    </Badge>
                                    <Badge
                                        v-for="tag in item.question.tags"
                                        :key="tag.id"
                                        variant="secondary"
                                        class="font-normal"
                                    >
                                        {{ tag.name }}
                                    </Badge>
                                </div>
                            </button>

                            <div
                                class="flex shrink-0 items-center gap-1 sm:pt-0.5"
                            >
                                <label
                                    class="sr-only"
                                    :for="`marks-${item.id}`"
                                >
                                    Marks for question {{ index + 1 }}
                                </label>
                                <Input
                                    :id="`marks-${item.id}`"
                                    v-model="marks[item.id]"
                                    type="number"
                                    min="0.5"
                                    max="100"
                                    step="0.5"
                                    class="h-8 w-20 text-right tabular-nums"
                                    :disabled="!quiz.can.edit_questions"
                                    @blur="saveMarks(item)"
                                    @keydown.enter.prevent="saveMarks(item)"
                                />
                                <span
                                    class="w-10 text-xs text-muted-foreground"
                                >
                                    marks
                                </span>
                                <Button
                                    v-if="quiz.can.edit_questions"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 text-muted-foreground hover:text-destructive"
                                    :aria-label="`Remove question ${index + 1}`"
                                    @click="remove(item)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </li>
                </ol>
                <div
                    class="flex items-center justify-between border-t bg-muted/40 px-4 py-2.5 text-sm"
                >
                    <span class="text-muted-foreground">
                        {{ rows.length }}
                        {{ rows.length === 1 ? 'question' : 'questions' }}
                    </span>
                    <span class="font-medium tabular-nums">
                        Total: {{ total() }} marks
                    </span>
                </div>
            </div>
        </template>

        <AddQuestionsSheet
            v-model:open="pickerOpen"
            :quiz-id="quiz.id"
            :types="types"
            :difficulties="difficulties"
            :tags="tags"
        />
        <QuestionPreviewDialog
            v-model:open="previewOpen"
            :question-id="previewId"
        />
    </QuizShell>
</template>
