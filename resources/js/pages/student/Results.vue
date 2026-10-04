<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Clock, Hourglass } from '@lucide/vue';
import StudentAnswer from '@/components/grading/StudentAnswer.vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Badge } from '@/components/ui/badge';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { formatInCourseTz } from '@/lib/datetime';
import { marks } from '@/lib/grading';
import type { AnswerOption } from '@/types';

type Item = {
    question: {
        type: string;
        body: string;
        code_language: string | null;
        explanation: string | null;
    };
    options: AnswerOption[];
    text_answer: string | null;
    code_answer: string | null;
    published: boolean;
    score: number | null;
    max_score: number;
    feedback: string | null;
};

const props = defineProps<{
    assessment: { title: string; timezone: string };
    student: { name: string; roll_number: string };
    submittedAt: string | null;
    started: boolean;
    released: boolean;
    items: Item[];
    total: number | null;
    maxScore: number | null;
}>();

// Check for new grades every 30 seconds (Inertia slows this down in background tabs) until
// everything is released and published, for up to 30 minutes.
const { start, stop } = usePoll(30_000, {}, { autoStart: false });
const startedAt = Date.now();
watch(
    () =>
        props.started &&
        (!props.released || props.total === null) &&
        Date.now() - startedAt < 30 * 60_000,
    (waiting) => (waiting ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head :title="`Results · ${assessment.title}`" />

    <StudentLayout :title="assessment.title">
        <div class="mx-auto w-full max-w-3xl space-y-6 py-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ assessment.title }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ student.name }} ·
                        <span class="font-mono">{{ student.roll_number }}</span>
                    </p>
                    <p v-if="submittedAt" class="text-sm text-muted-foreground">
                        Submitted
                        {{
                            formatInCourseTz(
                                submittedAt,
                                undefined,
                                assessment.timezone,
                            )
                        }}
                    </p>
                </div>
                <div
                    v-if="released"
                    class="rounded-xl border bg-background px-5 py-3 sm:text-right"
                >
                    <p class="text-xs text-muted-foreground">Total</p>
                    <p
                        v-if="total !== null"
                        class="text-2xl font-semibold tabular-nums"
                    >
                        {{ marks(total) }}
                        <span
                            class="text-base font-normal text-muted-foreground"
                        >
                            / {{ marks(maxScore) }}
                        </span>
                    </p>
                    <p v-else class="max-w-48 text-sm text-muted-foreground">
                        Total will appear once all answers are reviewed.
                    </p>
                </div>
            </div>

            <div
                v-if="!released"
                class="flex flex-col items-center gap-3 rounded-xl border bg-background px-6 py-12 text-center"
            >
                <Hourglass class="size-8 text-muted-foreground" />
                <p class="font-medium">
                    {{
                        started
                            ? 'Results are not available yet.'
                            : 'You haven’t taken this quiz.'
                    }}
                </p>
                <p
                    v-if="started"
                    class="max-w-sm text-sm text-muted-foreground"
                >
                    Your instructor will release them after grading. Keep this
                    link and check back later.
                </p>
            </div>

            <ol v-else class="space-y-4">
                <li
                    v-for="(item, i) in items"
                    :key="i"
                    class="space-y-4 rounded-xl border bg-background p-4 md:p-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-sm font-semibold">
                            Question {{ i + 1 }}
                        </span>
                        <span
                            v-if="item.published"
                            class="text-sm whitespace-nowrap tabular-nums"
                        >
                            <span class="font-semibold">
                                {{ marks(item.score) }}
                            </span>
                            <span class="text-muted-foreground">
                                / {{ marks(item.max_score) }}
                            </span>
                        </span>
                        <Badge v-else variant="secondary">
                            <Clock class="size-3" /> Under review
                        </Badge>
                    </div>
                    <Markdown :source="item.question.body" />
                    <div class="space-y-2">
                        <p class="text-xs font-medium text-muted-foreground">
                            Your answer
                        </p>
                        <StudentAnswer
                            :type="item.question.type"
                            :code-language="item.question.code_language"
                            :options="item.options"
                            :text-answer="item.text_answer"
                            :code-answer="item.code_answer"
                        />
                    </div>
                    <div
                        v-if="item.feedback"
                        class="rounded-lg bg-muted/50 p-3 text-sm"
                    >
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground"
                        >
                            Feedback
                        </p>
                        <p class="whitespace-pre-wrap">{{ item.feedback }}</p>
                    </div>
                    <div
                        v-if="item.question.explanation"
                        class="rounded-lg border border-dashed p-3 text-sm"
                    >
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground"
                        >
                            Explanation
                        </p>
                        <Markdown :source="item.question.explanation" />
                    </div>
                </li>
            </ol>
        </div>
    </StudentLayout>
</template>
