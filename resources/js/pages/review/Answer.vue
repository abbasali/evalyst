<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    ChevronLeft,
    ChevronRight,
    Pencil,
    RotateCcw,
    Sparkles,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import AuditTrail from '@/components/audit/AuditTrail.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import GradeForm from '@/components/grading/GradeForm.vue';
import GradeStatusBadge from '@/components/grading/GradeStatusBadge.vue';
import StudentAnswer from '@/components/grading/StudentAnswer.vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { marks, reasonLabel } from '@/lib/grading';
import { show as attemptShow } from '@/routes/attempts';
import { index } from '@/routes/review';
import { accept, regrade, show } from '@/routes/review/answers';
import type {
    AuditEntry,
    InstructorAnswer,
    ReviewFilters,
    Team,
} from '@/types';

const props = defineProps<{
    answer: InstructorAnswer;
    student: { name: string; roll_number: string };
    assessment: { id: number; title: string };
    attempt: {
        id: number;
        focus_lost: number;
        pastes: number;
        fullscreen_exits: number;
    };
    position: number;
    audit: AuditEntry[];
    nav: {
        index: number | null;
        total: number;
        next: number | null;
        previous: number | null;
    };
    filters: ReviewFilters;
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            { title: 'Review', href: index(props.currentTeam.slug) },
            { title: 'Answer', href: '#' },
        ],
    }),
});

const { slug } = useCourse();
const ai = computed(() => props.answer.ai);
const canAccept = computed(
    () =>
        props.answer.status === 'needs_review' &&
        ai.value?.score !== null &&
        ai.value !== null,
);
const editing = ref(false);
const processing = ref(false);
const regradeOpen = ref(false);

const inboxUrl = computed(() =>
    index.url(slug.value, { query: { ...props.filters } }),
);

function go(id: number | null) {
    router.visit(
        id === null
            ? inboxUrl.value
            : show.url([slug.value, id], { query: { ...props.filters } }),
    );
}

// Captured before deciding: once decided, the answer leaves the inbox and the reloaded nav no longer knows where it was.
let nextAfterDecision: number | null = null;

function rememberNext() {
    nextAfterDecision = props.nav.next;
}

function afterDecision() {
    editing.value = false;
    go(nextAfterDecision);
}

function decisionError(errors: Record<string, string>) {
    const message = errors.decision ?? errors.score;

    if (message) {
        toast.error(message);
    }
}

function acceptGrade() {
    if (!canAccept.value || processing.value) {
        return;
    }

    rememberNext();
    router.post(
        accept.url([slug.value, props.answer.id]),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: afterDecision,
            onError: decisionError,
        },
    );
}

function sendToAi() {
    router.post(
        regrade.url([slug.value, props.answer.id]),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => {
                processing.value = false;
                regradeOpen.value = false;
            },
            onError: decisionError,
        },
    );
}

function retryOrRegrade() {
    if (props.answer.published_at) {
        regradeOpen.value = true;
    } else {
        sendToAi();
    }
}

// a accept · e edit · j/k next/previous (not while typing).
useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    const target = event.target as HTMLElement | null;

    if (
        event.repeat ||
        editing.value ||
        regradeOpen.value ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        target?.closest('[role="dialog"]') ||
        target?.closest('input, textarea, select, [contenteditable="true"]')
    ) {
        return;
    }

    if (event.key === 'a') {
        acceptGrade();
    } else if (event.key === 'e') {
        event.preventDefault();
        rememberNext();
        editing.value = true;
    } else if (event.key === 'j' && props.nav.next !== null) {
        go(props.nav.next);
    } else if (event.key === 'k' && props.nav.previous !== null) {
        go(props.nav.previous);
    }
});

const activity = computed(() =>
    [
        { label: 'focus losses', count: props.attempt.focus_lost },
        { label: 'pastes', count: props.attempt.pastes },
        { label: 'fullscreen exits', count: props.attempt.fullscreen_exits },
    ].filter((item) => item.count > 0),
);
</script>

<template>
    <Head :title="`Review · ${student.name}`" />

    <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link :href="inboxUrl"><ArrowLeft /> Inbox</Link>
            </Button>
            <div class="flex items-center gap-1 text-sm text-muted-foreground">
                <span v-if="nav.index" class="mr-2 tabular-nums">
                    {{ nav.index }} of {{ nav.total }}
                </span>
                <Button
                    variant="outline"
                    size="icon"
                    class="size-8"
                    :disabled="nav.previous === null"
                    aria-label="Previous (k)"
                    @click="go(nav.previous)"
                >
                    <ChevronLeft />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    class="size-8"
                    :disabled="nav.next === null"
                    aria-label="Next (j)"
                    @click="go(nav.next)"
                >
                    <ChevronRight />
                </Button>
            </div>
        </div>

        <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ student.name }}
                </h1>
                <span class="font-mono text-sm text-muted-foreground">
                    {{ student.roll_number }}
                </span>
                <GradeStatusBadge :status="answer.status" />
            </div>
            <p class="text-sm text-muted-foreground">
                {{ assessment.title }} · Question {{ position }} ·
                {{ answer.question.type_label }} ·
                <Link
                    :href="attemptShow([slug, attempt.id])"
                    class="underline-offset-2 hover:underline"
                >
                    Whole attempt
                </Link>
            </p>
            <p
                v-if="activity.length"
                class="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400"
            >
                <AlertTriangle class="size-3.5" />
                During the quiz:
                {{
                    activity
                        .map((item) => `${item.count} ${item.label}`)
                        .join(', ')
                }}
            </p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="space-y-4 self-start rounded-xl border p-4 md:p-5">
                <div class="space-y-2">
                    <h2
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Question · {{ marks(answer.max_score) }} marks
                    </h2>
                    <Markdown :source="answer.question.body" />
                </div>
                <div v-if="answer.question.rubric" class="space-y-2">
                    <h2
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Rubric
                    </h2>
                    <Markdown
                        :source="answer.question.rubric"
                        class="text-sm"
                    />
                </div>
                <div v-if="answer.question.model_answer" class="space-y-2">
                    <h2
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Model answer
                    </h2>
                    <Markdown
                        :source="answer.question.model_answer"
                        class="text-sm"
                    />
                </div>
            </section>

            <div class="space-y-5">
                <section class="space-y-3 rounded-xl border p-4 md:p-5">
                    <h2
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        Student's answer
                    </h2>
                    <StudentAnswer
                        :type="answer.question.type"
                        :code-language="answer.question.code_language"
                        :options="answer.options"
                        :text-answer="answer.text_answer"
                        :code-answer="answer.code_answer"
                    />
                </section>

                <Alert v-if="answer.status === 'failed'" variant="destructive">
                    <AlertTriangle class="size-4" />
                    <AlertTitle>AI grading failed</AlertTitle>
                    <AlertDescription>
                        {{ ai?.error ?? 'The AI could not grade this answer.' }}
                        Retry, or grade it yourself.
                    </AlertDescription>
                </Alert>

                <section
                    v-if="ai && ai.score !== null"
                    class="space-y-3 rounded-xl border bg-muted/20 p-4 md:p-5"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2
                            class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            <Sparkles class="size-3.5" /> AI suggestion
                        </h2>
                        <span
                            v-if="ai.confidence !== null"
                            class="text-xs text-muted-foreground"
                        >
                            {{ Math.round(ai.confidence * 100) }}% confident
                        </span>
                    </div>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ marks(ai.score) }}
                        <span
                            class="text-base font-normal text-muted-foreground"
                        >
                            / {{ marks(answer.max_score) }}
                        </span>
                    </p>
                    <div v-if="ai.reasons.length" class="flex flex-wrap gap-1">
                        <Badge
                            v-for="reason in ai.reasons"
                            :key="reason"
                            :variant="
                                reason === 'flag:prompt_injection'
                                    ? 'destructive'
                                    : 'warning'
                            "
                        >
                            {{ reasonLabel(reason) }}
                        </Badge>
                    </div>
                    <p v-if="ai.feedback" class="text-sm whitespace-pre-wrap">
                        {{ ai.feedback }}
                    </p>
                    <table v-if="ai.breakdown.length" class="w-full text-sm">
                        <tbody class="divide-y">
                            <tr v-for="(row, i) in ai.breakdown" :key="i">
                                <td class="py-1.5 pr-3">
                                    {{ row.criterion }}
                                    <div
                                        v-if="row.note"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ row.note }}
                                    </div>
                                </td>
                                <td
                                    class="py-1.5 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{ marks(row.awarded) }} /
                                    {{ marks(row.max) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section
                    v-if="answer.status === 'final' || answer.published_at"
                    class="space-y-1 rounded-xl border p-4 text-sm"
                >
                    <p class="font-medium">
                        Published: {{ marks(answer.score) }} /
                        {{ marks(answer.max_score) }}
                        <span
                            v-if="answer.graded_by"
                            class="font-normal text-muted-foreground"
                        >
                            by {{ answer.graded_by }}
                        </span>
                    </p>
                    <p
                        v-if="answer.feedback"
                        class="whitespace-pre-wrap text-muted-foreground"
                    >
                        {{ answer.feedback }}
                    </p>
                </section>

                <section class="rounded-xl border p-4 md:p-5">
                    <GradeForm
                        v-if="editing"
                        :answer-id="answer.id"
                        :max-score="answer.max_score"
                        :score="
                            answer.status === 'needs_review'
                                ? (ai?.score ?? answer.score)
                                : (answer.score ?? ai?.score ?? null)
                        "
                        :feedback="
                            answer.status === 'needs_review'
                                ? (ai?.feedback ?? answer.feedback)
                                : (answer.feedback ?? ai?.feedback ?? null)
                        "
                        @saved="afterDecision"
                        @cancel="editing = false"
                    />
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="canAccept"
                            :disabled="processing"
                            @click="acceptGrade"
                        >
                            <Spinner v-if="processing" />
                            <Check v-else />
                            Accept {{ marks(ai?.score) }}
                            <kbd class="ml-1 text-xs opacity-60">A</kbd>
                        </Button>
                        <Button
                            :variant="canAccept ? 'outline' : 'default'"
                            @click="
                                rememberNext();
                                editing = true;
                            "
                        >
                            <Pencil />
                            {{
                                answer.status === 'final'
                                    ? 'Edit grade'
                                    : 'Edit & publish'
                            }}
                            <kbd class="ml-1 text-xs opacity-60">E</kbd>
                        </Button>
                        <Button
                            v-if="
                                answer.question.type.startsWith('open') &&
                                !answer.is_blank &&
                                answer.status !== 'pending'
                            "
                            variant="outline"
                            :disabled="processing"
                            @click="retryOrRegrade"
                        >
                            <RotateCcw />
                            {{
                                answer.status === 'failed'
                                    ? 'Retry AI'
                                    : 'Grade again with AI'
                            }}
                        </Button>
                        <Button
                            v-if="nav.next !== null"
                            variant="ghost"
                            @click="go(nav.next)"
                        >
                            Skip <ChevronRight />
                        </Button>
                    </div>
                </section>

                <AuditTrail :entries="audit" />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="regradeOpen"
            title="Grade this answer again?"
            description="This grade is already published, so the student may see a different score. The current grade stays visible until the new one is settled."
            confirm-label="Grade again"
            :processing="processing"
            @confirm="sendToAi"
        />
    </div>
</template>
