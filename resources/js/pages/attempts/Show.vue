<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Inbox,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Sparkles,
} from '@lucide/vue';
import { ref } from 'vue';
import AuditTrail from '@/components/audit/AuditTrail.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import GradeForm from '@/components/grading/GradeForm.vue';
import GradeStatusBadge from '@/components/grading/GradeStatusBadge.vue';
import StudentAnswer from '@/components/grading/StudentAnswer.vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { marks, reasonLabel } from '@/lib/grading';
import { index, results } from '@/routes/quizzes';
import { regrade as regradeQuestion } from '@/routes/quizzes/questions';
import { show as reviewShow } from '@/routes/review/answers';
import type { AuditEntry, InstructorAnswer, Team } from '@/types';

type Answer = InstructorAnswer & {
    assessment_question_id: number;
    audit: AuditEntry[];
};

const props = defineProps<{
    quiz: { id: number; title: string };
    student: { name: string; roll_number: string };
    attempt: {
        id: number;
        status: string;
        started_at: string;
        deadline_at: string;
        submitted_at: string | null;
        auto_submitted: boolean;
        score: number | null;
        max_score: number;
    };
    answers: Answer[];
    events: {
        type: string;
        occurred_at: string;
        meta: Record<string, unknown> | null;
    }[];
    participantAudit: AuditEntry[];
}>();

defineOptions({
    layout: (props: {
        currentTeam: Team;
        quiz: { id: number; title: string };
    }) => ({
        breadcrumbs: [
            { title: 'Quizzes', href: index(props.currentTeam.slug) },
            {
                title: props.quiz.title,
                href: results([props.currentTeam.slug, props.quiz.id]),
            },
            { title: 'Attempt', href: '#' },
        ],
    }),
});

const { slug } = useCourse();
const editing = ref<number | null>(null);
const regrading = ref<Answer | null>(null);
const regradeOpen = ref(false);
const processing = ref(false);

function askRegrade(answer: Answer) {
    regrading.value = answer;
    regradeOpen.value = true;
}

function confirmRegrade() {
    if (!regrading.value) {
        return;
    }

    router.post(
        regradeQuestion.url([
            slug.value,
            props.quiz.id,
            regrading.value.assessment_question_id,
        ]),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => {
                processing.value = false;
                regradeOpen.value = false;
            },
        },
    );
}

const eventLabels: Record<string, string> = {
    focus_lost: 'Left the quiz tab',
    pasted: 'Pasted text',
    fullscreen_exited: 'Exited fullscreen',
    resumed: 'Resumed',
    auto_submitted: 'Auto-submitted',
    force_submitted: 'Submitted by instructor',
    resume_allowed: 'Allowed to resume elsewhere',
};
</script>

<template>
    <Head :title="`${student.name} · ${quiz.title}`" />

    <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
        <Button variant="ghost" size="sm" as-child class="-ml-2 self-start">
            <Link :href="results([slug, quiz.id])">
                <ArrowLeft /> Results
            </Link>
        </Button>

        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ student.name }}
                    </h1>
                    <span class="font-mono text-sm text-muted-foreground">
                        {{ student.roll_number }}
                    </span>
                    <GradeStatusBadge :status="attempt.status" />
                </div>
                <p class="text-sm text-muted-foreground">
                    Started {{ formatInCourseTz(attempt.started_at) }} ·
                    <template v-if="attempt.submitted_at">
                        Submitted {{ formatInCourseTz(attempt.submitted_at) }}
                        <Badge
                            v-if="attempt.auto_submitted"
                            variant="outline"
                            class="ml-1"
                        >
                            auto
                        </Badge>
                    </template>
                    <template v-else>
                        Deadline {{ formatInCourseTz(attempt.deadline_at) }}
                    </template>
                </p>
            </div>
            <div class="rounded-xl border px-4 py-2 text-right">
                <p class="text-xs text-muted-foreground">Total</p>
                <p class="text-xl font-semibold tabular-nums">
                    {{ marks(attempt.score) }}
                    <span class="text-sm font-normal text-muted-foreground">
                        / {{ marks(attempt.max_score) }}
                    </span>
                </p>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-[1fr_18rem]">
            <ol class="space-y-4">
                <li
                    v-for="(answer, i) in answers"
                    :key="answer.id"
                    class="space-y-4 rounded-xl border p-4 md:p-5"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold"
                                >Q{{ i + 1 }}</span
                            >
                            <span class="text-xs text-muted-foreground">
                                {{ answer.question.type_label }}
                            </span>
                            <GradeStatusBadge :status="answer.status" />
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="mr-1 text-sm tabular-nums">
                                <span class="font-semibold">
                                    {{ marks(answer.score) }}
                                </span>
                                <span class="text-muted-foreground">
                                    / {{ marks(answer.max_score) }}
                                </span>
                            </span>
                            <Button
                                v-if="
                                    answer.status === 'needs_review' ||
                                    answer.status === 'failed'
                                "
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link :href="reviewShow([slug, answer.id])">
                                    <Inbox /> Review
                                </Link>
                            </Button>
                            <Button
                                v-else-if="
                                    answer.status !== 'pending' &&
                                    answer.status !== 'ungraded'
                                "
                                size="sm"
                                variant="ghost"
                                @click="editing = answer.id"
                            >
                                <Pencil /> Edit grade
                            </Button>
                            <DropdownMenu
                                v-if="attempt.status !== 'in_progress'"
                            >
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        aria-label="More"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="askRegrade(answer)"
                                    >
                                        <RotateCcw /> Regrade this question for
                                        everyone
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <Markdown :source="answer.question.body" />

                    <StudentAnswer
                        :type="answer.question.type"
                        :code-language="answer.question.code_language"
                        :options="answer.options"
                        :text-answer="answer.text_answer"
                        :code-answer="answer.code_answer"
                    />

                    <GradeForm
                        v-if="editing === answer.id"
                        :answer-id="answer.id"
                        :max-score="answer.max_score"
                        :score="answer.score"
                        :feedback="answer.feedback"
                        class="rounded-lg border bg-muted/20 p-4"
                        @saved="editing = null"
                        @cancel="editing = null"
                    />

                    <div
                        v-else-if="answer.feedback"
                        class="rounded-lg bg-muted/40 p-3 text-sm"
                    >
                        <p
                            class="mb-1 text-xs font-medium text-muted-foreground"
                        >
                            Feedback
                            <template v-if="answer.graded_by">
                                · by {{ answer.graded_by }}
                            </template>
                        </p>
                        <p class="whitespace-pre-wrap">{{ answer.feedback }}</p>
                    </div>

                    <details
                        v-if="answer.ai"
                        class="group rounded-lg border px-3 py-2 text-sm"
                    >
                        <summary
                            class="flex cursor-pointer items-center gap-1.5 text-xs font-medium text-muted-foreground"
                        >
                            <Sparkles class="size-3.5" /> AI grading
                            <template v-if="answer.ai.score !== null">
                                · suggested {{ marks(answer.ai.score) }}
                            </template>
                            <template v-if="answer.ai.confidence !== null">
                                · {{ Math.round(answer.ai.confidence * 100) }}%
                                confident
                            </template>
                        </summary>
                        <div class="mt-2 space-y-2">
                            <div
                                v-if="answer.ai.reasons.length"
                                class="flex flex-wrap gap-1"
                            >
                                <Badge
                                    v-for="reason in answer.ai.reasons"
                                    :key="reason"
                                    variant="warning"
                                >
                                    {{ reasonLabel(reason) }}
                                </Badge>
                            </div>
                            <p v-if="answer.ai.error" class="text-destructive">
                                {{ answer.ai.error }}
                            </p>
                            <p
                                v-if="answer.ai.feedback"
                                class="whitespace-pre-wrap text-muted-foreground"
                            >
                                {{ answer.ai.feedback }}
                            </p>
                            <ul
                                v-if="answer.ai.breakdown.length"
                                class="space-y-1 text-xs"
                            >
                                <li
                                    v-for="(row, j) in answer.ai.breakdown"
                                    :key="j"
                                    class="flex justify-between gap-3"
                                >
                                    <span>{{ row.criterion }}</span>
                                    <span class="tabular-nums">
                                        {{ marks(row.awarded) }} /
                                        {{ marks(row.max) }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </details>

                    <AuditTrail
                        v-if="answer.audit.length"
                        :entries="answer.audit"
                    />
                </li>
            </ol>

            <aside class="space-y-5">
                <section class="space-y-2 rounded-xl border p-4">
                    <h3
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Activity
                    </h3>
                    <p
                        v-if="events.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nothing unusual recorded.
                    </p>
                    <ol
                        v-else
                        class="max-h-96 space-y-1.5 overflow-y-auto text-sm"
                    >
                        <li
                            v-for="(event, i) in events"
                            :key="i"
                            class="flex justify-between gap-3"
                        >
                            <span>
                                {{ eventLabels[event.type] ?? event.type }}
                                <span
                                    v-if="event.meta?.length"
                                    class="text-xs text-muted-foreground"
                                >
                                    ({{ event.meta.length }} chars)
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground tabular-nums"
                            >
                                {{
                                    formatInCourseTz(event.occurred_at, {
                                        timeStyle: 'medium',
                                    })
                                }}
                            </span>
                        </li>
                    </ol>
                </section>
                <section class="rounded-xl border p-4">
                    <AuditTrail
                        :entries="participantAudit"
                        title="Attempt history"
                    />
                </section>
            </aside>
        </div>

        <ConfirmDialog
            v-model:open="regradeOpen"
            title="Regrade this question for every student?"
            description="Open answers go back to the AI and choice answers are rescored with the current answer key and policy. Answers you graded by hand are kept. Students may see scores change; current grades stay visible until the new ones are settled."
            confirm-label="Regrade"
            :processing="processing"
            @confirm="confirmRegrade"
        />
    </div>
</template>
