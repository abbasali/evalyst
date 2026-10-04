<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Clipboard,
    Eye,
    Maximize,
    MoreHorizontal,
    RotateCcw,
    Send,
    Unlock,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { marks } from '@/lib/grading';
import { cn } from '@/lib/utils';
import { index } from '@/routes/quizzes';
import { allowResume, forceSubmit, reset } from '@/routes/quizzes/participants';
import type { QuizShellProps, Team } from '@/types';

type Row = {
    id: number;
    name: string;
    roll_number: string;
    status: 'not_started' | 'in_progress' | 'submitted';
    started_at: string | null;
    deadline_at: string | null;
    submitted_at: string | null;
    auto_submitted: boolean;
    answered: number;
    score: number | null;
    max_score: number | null;
    score_state: 'live' | 'grading' | 'final' | null;
    total: number | null;
    flagged: number;
    focus_lost: number;
    pastes: number;
    fullscreen_exits: number;
    resume_allowed_until: string | null;
};

const props = defineProps<
    QuizShellProps & {
        rows: Row[];
        summary: {
            not_started: number;
            in_progress: number;
            submitted: number;
        };
        serverNow: string;
        alertAt: number;
        canAllowResume: boolean;
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

// Refresh every 5s while students could be taking it.
const live = computed(
    () =>
        props.quiz.state === 'open' ||
        props.quiz.state === 'upcoming' ||
        props.summary.in_progress > 0,
);
const poll = usePoll(
    5000,
    { only: ['rows', 'summary', 'serverNow', 'quiz'] },
    { autoStart: false },
);

// A local clock for "time left", corrected by the server time of the last refresh.
const now = ref(Date.now());
const offset = computed(() => new Date(props.serverNow).getTime() - Date.now());
let clock: ReturnType<typeof setInterval> | undefined;

watch(live, (isLive) => (isLive ? poll.start() : poll.stop()));

onMounted(() => {
    if (live.value) {
        poll.start();
    }

    clock = setInterval(() => (now.value = Date.now()), 1000);
});
onBeforeUnmount(() => clearInterval(clock));

function timeLeft(row: Row): string {
    if (!row.deadline_at) {
        return '—';
    }

    const ms = Math.max(
        0,
        new Date(row.deadline_at).getTime() - (now.value + offset.value),
    );
    const minutes = Math.floor(ms / 60000);
    const seconds = String(Math.floor((ms % 60000) / 1000)).padStart(2, '0');

    return `${minutes}:${seconds}`;
}

const statusMeta = {
    not_started: { label: 'Not started', variant: 'outline' },
    in_progress: { label: 'In progress', variant: 'info' },
    submitted: { label: 'Submitted', variant: 'success' },
} as const;

const args = (row: Row) =>
    [slug.value, props.quiz.id, row.id] as [string, number, number];

// Actions
const confirming = ref<{ row: Row; action: 'allow' | 'submit' } | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function ask(row: Row, action: 'allow' | 'submit') {
    confirming.value = { row, action };
    confirmOpen.value = true;
}

function confirm() {
    if (!confirming.value) {
        return;
    }

    const { row, action } = confirming.value;
    router.post(
        (action === 'allow' ? allowResume : forceSubmit).url(args(row)),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (confirmOpen.value = false),
        },
    );
}

const resetting = ref<Row | null>(null);
const resetOpen = ref(false);
const resetForm = useForm({ roll_number: '' });

function askReset(row: Row) {
    resetting.value = row;
    resetForm.reset();
    resetForm.clearErrors();
    resetOpen.value = true;
}

function doReset() {
    if (!resetting.value) {
        return;
    }

    resetForm.post(reset.url(args(resetting.value)), {
        preserveScroll: true,
        onSuccess: () => (resetOpen.value = false),
    });
}

const flagged = (count: number) => count >= props.alertAt;
</script>

<template>
    <Head :title="`${quiz.title} · Monitor`" />

    <QuizShell :quiz="quiz" :checklist="checklist" tab="monitor">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2">
                <div
                    v-for="item in [
                        { label: 'Not started', value: summary.not_started },
                        { label: 'In progress', value: summary.in_progress },
                        { label: 'Submitted', value: summary.submitted },
                    ]"
                    :key="item.label"
                    class="rounded-lg border px-4 py-2"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ item.label }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ item.value }}
                    </p>
                </div>
            </div>
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <span
                    :class="
                        cn(
                            'size-2 rounded-full',
                            live
                                ? 'animate-pulse bg-emerald-500'
                                : 'bg-muted-foreground/40',
                        )
                    "
                />
                {{ live ? 'Live — updates every 5 seconds' : 'Not live' }}
            </p>
        </div>

        <EmptyState
            v-if="rows.length === 0"
            :icon="Users"
            title="Nobody here yet"
            :description="
                quiz.access_mode === 'roster'
                    ? 'Add students on the Access tab.'
                    : 'Students appear here as they join with the shared code.'
            "
        />

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Student</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Progress</th>
                        <th class="px-4 py-3 font-medium">Time left</th>
                        <th class="px-4 py-3 text-right font-medium">Score</th>
                        <th class="px-4 py-3 font-medium">Activity</th>
                        <th class="w-12 px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="transition-colors hover:bg-muted/30"
                    >
                        <td class="px-4 py-2.5">
                            <p class="font-medium">{{ row.name }}</p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ row.roll_number }}
                            </p>
                        </td>
                        <td class="px-4 py-2.5">
                            <Badge :variant="statusMeta[row.status].variant">
                                {{ statusMeta[row.status].label }}
                            </Badge>
                            <p
                                v-if="row.status === 'submitted'"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{
                                    formatInCourseTz(row.submitted_at, {
                                        timeStyle: 'short',
                                    })
                                }}
                                <template v-if="row.auto_submitted">
                                    · auto
                                </template>
                            </p>
                            <p
                                v-else-if="row.resume_allowed_until"
                                class="mt-1 text-xs text-sky-700 dark:text-sky-400"
                            >
                                Resume allowed
                            </p>
                        </td>
                        <td class="px-4 py-2.5 tabular-nums">
                            <template v-if="row.total">
                                {{ row.answered }}/{{ row.total }}
                                <span
                                    v-if="row.flagged"
                                    class="text-xs text-muted-foreground"
                                >
                                    · {{ row.flagged }} flagged
                                </span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-2.5 font-mono tabular-nums">
                            {{
                                row.status === 'in_progress'
                                    ? timeLeft(row)
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <template v-if="row.score !== null">
                                <span class="font-medium tabular-nums">
                                    {{ marks(row.score) }}
                                </span>
                                <span
                                    class="text-muted-foreground tabular-nums"
                                >
                                    / {{ marks(row.max_score) }}
                                </span>
                                <span
                                    v-if="row.score_state !== 'final'"
                                    class="block text-xs text-muted-foreground"
                                    :title="
                                        row.score_state === 'live'
                                            ? 'Choice answers so far. Written answers are graded after submitting.'
                                            : 'Some written answers are still being graded or reviewed.'
                                    "
                                >
                                    {{
                                        row.score_state === 'live'
                                            ? 'choices so far'
                                            : 'grading…'
                                    }}
                                </span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div
                                v-if="row.status !== 'not_started'"
                                class="flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="metric in [
                                        {
                                            icon: Eye,
                                            value: row.focus_lost,
                                            label: 'left the tab',
                                        },
                                        {
                                            icon: Clipboard,
                                            value: row.pastes,
                                            label: 'pasted',
                                        },
                                        {
                                            icon: Maximize,
                                            value: row.fullscreen_exits,
                                            label: 'left fullscreen',
                                        },
                                    ].filter((metric) => metric.value > 0)"
                                    :key="metric.label"
                                    :title="`${metric.label} ${metric.value} ${metric.value === 1 ? 'time' : 'times'}`"
                                    :class="
                                        cn(
                                            'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs tabular-nums',
                                            flagged(metric.value)
                                                ? 'border-amber-500/50 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                                : 'text-muted-foreground',
                                        )
                                    "
                                >
                                    <AlertTriangle
                                        v-if="flagged(metric.value)"
                                        class="size-3"
                                    />
                                    <component
                                        :is="metric.icon"
                                        v-else
                                        class="size-3"
                                    />
                                    {{ metric.value }} {{ metric.label }}
                                </span>
                                <span
                                    v-if="
                                        !row.focus_lost &&
                                        !row.pastes &&
                                        !row.fullscreen_exits
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    None
                                </span>
                            </div>
                        </td>
                        <td class="px-2 py-2 text-right">
                            <DropdownMenu v-if="row.status !== 'not_started'">
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${row.name}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        v-if="
                                            canAllowResume &&
                                            row.status === 'in_progress'
                                        "
                                        @click="ask(row, 'allow')"
                                    >
                                        <Unlock /> Allow resume on another
                                        device
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="row.status === 'in_progress'"
                                        @click="ask(row, 'submit')"
                                    >
                                        <Send /> Submit now
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        variant="destructive"
                                        @click="askReset(row)"
                                    >
                                        <RotateCcw /> Reset attempt
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="
                confirming?.action === 'allow'
                    ? 'Allow resume on another device?'
                    : 'Submit this attempt now?'
            "
            :description="
                confirming
                    ? confirming.action === 'allow'
                        ? `For the next 10 minutes, ${confirming.row.name} can continue from any browser by entering the shared code and their roll number. Their timer keeps running.`
                        : `${confirming.row.name}'s answers so far are submitted and they can't change them.`
                    : ''
            "
            :confirm-label="
                confirming?.action === 'allow' ? 'Allow resume' : 'Submit now'
            "
            :processing="processing"
            @confirm="confirm"
        />

        <Dialog v-model:open="resetOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle
                        >Reset {{ resetting?.name }}'s attempt?</DialogTitle
                    >
                    <DialogDescription>
                        Their answers are deleted and can't be recovered. They
                        can start the quiz again with a fresh timer while it is
                        open.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-2" @submit.prevent="doReset">
                    <Label for="confirm-roll">
                        Type the roll number
                        <span class="font-mono">{{
                            resetting?.roll_number
                        }}</span>
                        to confirm
                    </Label>
                    <Input
                        id="confirm-roll"
                        v-model="resetForm.roll_number"
                        autocomplete="off"
                    />
                    <InputError :message="resetForm.errors.roll_number" />
                    <DialogFooter class="gap-2 pt-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="resetOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="
                                resetForm.processing ||
                                resetForm.roll_number.trim().toUpperCase() !==
                                    resetting?.roll_number
                            "
                        >
                            <Spinner v-if="resetForm.processing" />
                            Reset attempt
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </QuizShell>
</template>
