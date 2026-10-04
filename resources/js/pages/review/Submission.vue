<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Bot,
    Check,
    CircleCheck,
    CircleX,
    ExternalLink,
    FileCode2,
    RotateCcw,
    Wrench,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AuditTrail from '@/components/audit/AuditTrail.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import GradeStatusBadge from '@/components/grading/GradeStatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { marks, reasonLabel } from '@/lib/grading';
import { submissions as submissionsTab } from '@/routes/assignments';
import { index } from '@/routes/review';
import { regrade, update } from '@/routes/review/submissions';
import type { AuditEntry, Team } from '@/types';

type Rule = {
    id: number;
    result_id: number | null;
    kind: 'automated' | 'ai';
    title: string;
    description: string | null;
    max_score: number;
    score: number | null;
    passed: boolean | null;
    reasoning: string | null;
    evidence: string[];
    confidence: number | null;
    overridden: boolean;
};

const props = defineProps<{
    submission: {
        id: number;
        status: string;
        is_current: boolean;
        repo_url: string;
        commit_url: string;
        short_sha: string;
        sha: string;
        submitted_at: string;
        minutes_late: number;
        raw_score: number | null;
        penalty: number;
        score: number | null;
        max_score: number;
        feedback: string | null;
        flags: string[];
        reasons: string[];
        error: string | null;
        published_at: string | null;
        graded_by: string | null;
        manifest: {
            included: string[];
            skipped: Record<string, string>;
            total_files: number;
            token_estimate: number;
            commit_count: number;
            truncated: boolean;
            skipped_count?: number;
        } | null;
    };
    rules: Rule[];
    student: { name: string; roll_number: string };
    assignment: { id: number; title: string };
    late: {
        deadline: string;
        waived: boolean;
        override: number | null;
        note: string | null;
    };
    audit: AuditEntry[];
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            { title: 'Review', href: index(props.currentTeam.slug) },
            { title: 'Submission', href: '#' },
        ],
    }),
});

const { slug } = useCourse();
const graded = computed(() => props.rules.every((rule) => rule.result_id));
const decidable = computed(
    () =>
        graded.value &&
        !['submitted', 'grading'].includes(props.submission.status),
);

const form = useForm({
    rules: Object.fromEntries(
        props.rules
            .filter((rule) => rule.result_id)
            .map((rule) => [
                rule.result_id as number,
                { score: rule.score ?? 0, reasoning: rule.reasoning ?? '' },
            ]),
    ) as Record<number, { score: number | string; reasoning: string }>,
    feedback: props.submission.feedback ?? '',
});

const raw = computed(() =>
    props.rules.reduce(
        (sum, rule) =>
            sum +
            (rule.result_id
                ? Number(form.rules[rule.result_id]?.score ?? 0) || 0
                : 0),
        0,
    ),
);
const final = computed(() =>
    Math.max(0, Math.round((raw.value - props.submission.penalty) * 100) / 100),
);

// Only the rules the instructor changed are sent (AI and partial scores needn't be 0.5 steps).
const initial = Object.fromEntries(
    props.rules
        .filter((rule) => rule.result_id)
        .map((rule) => [
            rule.result_id as number,
            { score: rule.score ?? 0, reasoning: rule.reasoning ?? '' },
        ]),
);

function publish() {
    form.transform((data) => ({
        feedback: data.feedback,
        rules: Object.fromEntries(
            Object.entries(data.rules).filter(
                ([id, rule]) =>
                    Number(rule.score) !== Number(initial[Number(id)]?.score) ||
                    rule.reasoning !== initial[Number(id)]?.reasoning,
            ),
        ),
    })).submit(update([slug.value, props.submission.id]), {
        preserveScroll: true,
        onError: (errors) => {
            const message = (errors as Record<string, string>).decision;

            if (message) {
                toast.error(message);
            }
        },
    });
}

// Refresh while grading runs in the background.
const { start: startPolling, stop: stopPolling } = usePoll(
    4000,
    {},
    { autoStart: false },
);
watch(
    () => ['submitted', 'grading'].includes(props.submission.status),
    (grading) => (grading ? startPolling() : stopPolling()),
    { immediate: true },
);

const regradeOpen = ref(false);
const processing = ref(false);

function sendRegrade() {
    router.post(
        regrade.url([slug.value, props.submission.id]),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => {
                processing.value = false;
                regradeOpen.value = false;
            },
            onError: (errors) => {
                const message = (errors as Record<string, string>).decision;

                if (message) {
                    toast.error(message);
                }
            },
        },
    );
}

function evidenceUrl(item: string): string | null {
    if (/^[0-9a-f]{7,40}\b/.test(item)) {
        return `${props.submission.repo_url}/commit/${item.split(' ')[0]}`;
    }

    if (/^[\w.\-/]+\.\w+$/.test(item)) {
        return `${props.submission.repo_url}/blob/${props.submission.sha}/${item}`;
    }

    return null;
}

const skipped = computed(() =>
    Object.entries(props.submission.manifest?.skipped ?? {}),
);

function lateLabel(minutes: number): string {
    return minutes < 60
        ? `${minutes} min late`
        : minutes < 1440
          ? `${Math.ceil(minutes / 60)} h late`
          : `${Math.floor(minutes / 1440)} d ${Math.floor((minutes % 1440) / 60)} h late`;
}
</script>

<template>
    <Head :title="`Review · ${student.name}`" />

    <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <Button variant="ghost" size="sm" as-child class="-ml-2">
                <Link :href="index(slug)"><ArrowLeft /> Inbox</Link>
            </Button>
            <Button variant="ghost" size="sm" as-child>
                <Link :href="submissionsTab([slug, assignment.id])">
                    All submissions
                </Link>
            </Button>
        </div>

        <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ student.name }}
                </h1>
                <span class="font-mono text-sm text-muted-foreground">
                    {{ student.roll_number }}
                </span>
                <GradeStatusBadge :status="submission.status" />
                <Badge v-if="!submission.is_current" variant="outline">
                    replaced by a newer submission
                </Badge>
            </div>
            <p
                class="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground"
            >
                {{ assignment.title }} ·
                <a
                    :href="submission.commit_url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 font-mono hover:underline"
                >
                    {{
                        submission.repo_url.replace('https://github.com/', '')
                    }}@{{ submission.short_sha }}
                    <ExternalLink class="size-3" />
                </a>
                · submitted {{ formatInCourseTz(submission.submitted_at) }}
                <Badge v-if="submission.minutes_late > 0" variant="warning">
                    {{ lateLabel(submission.minutes_late) }}
                </Badge>
            </p>
        </div>

        <Alert v-if="submission.status === 'failed'" variant="destructive">
            <AlertTriangle class="size-4" />
            <AlertTitle>Grading failed</AlertTitle>
            <AlertDescription>
                {{ submission.error ?? 'Something went wrong.' }} Retry, and if
                it keeps failing, check that the repository is still public.
            </AlertDescription>
        </Alert>
        <Alert v-else-if="['submitted', 'grading'].includes(submission.status)">
            <Bot class="size-4" />
            <AlertTitle>Being graded</AlertTitle>
            <AlertDescription>
                This page updates by itself when grading finishes.
            </AlertDescription>
        </Alert>

        <div
            v-if="submission.reasons.length || submission.flags.length"
            class="flex flex-wrap gap-1"
        >
            <Badge
                v-for="reason in [
                    ...new Set([
                        ...submission.reasons,
                        ...submission.flags.map((flag) => `flag:${flag}`),
                    ]),
                ]"
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

        <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
            <div class="space-y-4">
                <section
                    v-for="rule in rules"
                    :key="rule.id"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0 space-y-0.5">
                            <p class="flex items-center gap-1.5 font-medium">
                                <component
                                    :is="rule.kind === 'ai' ? Bot : Wrench"
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                {{ rule.title }}
                                <template v-if="rule.passed !== null">
                                    <CircleCheck
                                        v-if="rule.passed"
                                        class="size-4 text-emerald-600"
                                    />
                                    <CircleX
                                        v-else
                                        class="size-4 text-destructive"
                                    />
                                </template>
                            </p>
                            <p
                                v-if="rule.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ rule.description }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="rule.confidence !== null"
                                class="text-xs text-muted-foreground"
                            >
                                {{ Math.round(rule.confidence * 100) }}% sure
                            </span>
                            <Badge v-if="rule.overridden" variant="outline">
                                edited
                            </Badge>
                        </div>
                    </div>

                    <template v-if="rule.result_id">
                        <div class="flex items-center gap-2">
                            <Label :for="`score-${rule.id}`" class="sr-only">
                                Score
                            </Label>
                            <Input
                                :id="`score-${rule.id}`"
                                v-model="form.rules[rule.result_id].score"
                                type="number"
                                min="0"
                                :max="rule.max_score"
                                step="0.5"
                                class="w-24"
                                :disabled="!decidable"
                            />
                            <span class="text-sm text-muted-foreground">
                                / {{ marks(rule.max_score) }}
                            </span>
                        </div>
                        <InputError
                            :message="
                                (form.errors as Record<string, string>)[
                                    `rules.${rule.result_id}.score`
                                ]
                            "
                        />
                        <Textarea
                            v-model="form.rules[rule.result_id].reasoning"
                            rows="2"
                            class="text-sm"
                            :disabled="!decidable"
                        />
                        <div
                            v-if="rule.evidence.length"
                            class="flex flex-wrap gap-1"
                        >
                            <template v-for="item in rule.evidence" :key="item">
                                <a
                                    v-if="evidenceUrl(item)"
                                    :href="evidenceUrl(item)!"
                                    target="_blank"
                                    rel="noopener"
                                    class="rounded border px-1.5 font-mono text-xs hover:bg-muted"
                                >
                                    {{ item }}
                                </a>
                                <span
                                    v-else
                                    class="rounded border px-1.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ item }}
                                </span>
                            </template>
                        </div>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        Not graded yet.
                    </p>
                </section>

                <section class="space-y-2 rounded-xl border p-4">
                    <Label for="feedback">Feedback for the student</Label>
                    <Textarea
                        id="feedback"
                        v-model="form.feedback"
                        rows="5"
                        :disabled="!decidable"
                    />
                </section>
            </div>

            <aside class="space-y-4">
                <section class="space-y-2 rounded-xl border p-4 text-sm">
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Rules</span>
                        <span class="tabular-nums">
                            {{ marks(raw) }} / {{ marks(submission.max_score) }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted-foreground">Late penalty</span>
                        <span class="tabular-nums">
                            −{{ marks(submission.penalty) }}
                        </span>
                    </div>
                    <p
                        v-if="late.waived || late.override !== null"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            late.waived
                                ? 'Penalty waived'
                                : `Fixed penalty ${marks(late.override)}`
                        }}<template v-if="late.note">
                            · “{{ late.note }}”</template
                        >
                    </p>
                    <div
                        class="flex justify-between border-t pt-2 font-semibold"
                    >
                        <span>Final</span>
                        <span class="tabular-nums">{{ marks(final) }}</span>
                    </div>
                    <p
                        v-if="submission.published_at"
                        class="text-xs text-muted-foreground"
                    >
                        Published
                        {{ formatInCourseTz(submission.published_at) }}
                        <template v-if="submission.graded_by">
                            by {{ submission.graded_by }}
                        </template>
                    </p>

                    <div class="flex flex-col gap-2 pt-2">
                        <Button
                            v-if="decidable && submission.is_current"
                            :disabled="form.processing"
                            @click="publish"
                        >
                            <Spinner v-if="form.processing" />
                            <Check v-else />
                            {{
                                form.isDirty
                                    ? 'Save & publish'
                                    : 'Accept & publish'
                            }}
                        </Button>
                        <Button
                            v-if="
                                submission.is_current &&
                                !['submitted', 'grading'].includes(
                                    submission.status,
                                )
                            "
                            variant="outline"
                            :disabled="processing"
                            @click="
                                submission.published_at
                                    ? (regradeOpen = true)
                                    : sendRegrade()
                            "
                        >
                            <RotateCcw />
                            {{
                                submission.status === 'failed'
                                    ? 'Retry grading'
                                    : 'Grade again'
                            }}
                        </Button>
                    </div>
                </section>

                <details
                    v-if="submission.manifest"
                    class="rounded-xl border p-4 text-sm"
                >
                    <summary
                        class="flex cursor-pointer items-center gap-1.5 font-medium"
                    >
                        <FileCode2 class="size-4" />
                        Files read: {{ submission.manifest.included.length }} of
                        {{ submission.manifest.total_files }}
                    </summary>
                    <p class="mt-2 text-xs text-muted-foreground">
                        ~{{
                            submission.manifest.token_estimate.toLocaleString()
                        }}
                        tokens · {{ submission.manifest.commit_count }} commits
                        <template v-if="submission.manifest.truncated">
                            · tree truncated by GitHub
                        </template>
                        <template
                            v-if="
                                (submission.manifest.skipped_count ?? 0) >
                                skipped.length
                            "
                        >
                            · showing {{ skipped.length }} of
                            {{ submission.manifest.skipped_count }} skipped
                        </template>
                    </p>
                    <ul
                        class="mt-2 max-h-64 space-y-0.5 overflow-y-auto font-mono text-xs"
                    >
                        <li
                            v-for="path in submission.manifest.included"
                            :key="path"
                        >
                            {{ path }}
                        </li>
                        <li
                            v-for="[path, reason] in skipped"
                            :key="path"
                            class="text-muted-foreground line-through"
                            :title="reason"
                        >
                            {{ path }}
                            <span class="no-underline">({{ reason }})</span>
                        </li>
                    </ul>
                </details>

                <section class="rounded-xl border p-4">
                    <AuditTrail :entries="audit" />
                </section>
            </aside>
        </div>

        <ConfirmDialog
            v-model:open="regradeOpen"
            title="Grade this submission again?"
            description="It's graded again at the same commit. The current grade is hidden from the student until the new one is settled, and the score may change."
            confirm-label="Grade again"
            :processing="processing"
            @confirm="sendRegrade"
        />
    </div>
</template>
