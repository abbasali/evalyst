<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    ClipboardCheck,
    FolderGit2,
    History,
    Inbox,
    Library,
    Plus,
    Send,
    Sparkles,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { Button } from '@/components/ui/button';
import { formatInCourseTz } from '@/lib/datetime';
import { auditActionLabel } from '@/lib/grading';
import { dashboard } from '@/routes';
import { aiUsage } from '@/routes';
import { edit as assignmentEdit, submissions } from '@/routes/assignments';
import { release as releaseAssignment } from '@/routes/assignments/results';
import { create as createQuestion } from '@/routes/questions';
import { create as createQuiz, monitor, results } from '@/routes/quizzes';
import { release as releaseQuiz } from '@/routes/quizzes/results';
import { index as reviewIndex } from '@/routes/review';
import { index as studentsIndex } from '@/routes/students';
import type { DashboardInvitation, Team } from '@/types';

type Row = {
    id: number;
    type: 'quiz' | 'assignment';
    title: string;
    opens_at: string | null;
    closes_at: string;
    participants: number;
    started: number;
    submitted: number;
};

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    overview?: {
        active: Row[];
        upcoming: Row[];
        unreleased: Row[];
        needsReview: number;
        activity: {
            id: number;
            action: string;
            user: string | null;
            created_at: string | null;
            note: string | null;
        }[];
        aiSpendMonth: number;
        isEmpty: boolean;
        hasStudents: boolean;
        hasQuestions: boolean;
    };
    currentTeam?: Team | null;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});

const slug = () => props.currentTeam?.slug ?? '';

function rowHref(row: Row) {
    return row.type === 'quiz'
        ? monitor([slug(), row.id])
        : submissions([slug(), row.id]);
}

const releasing = ref<Row | null>(null);
const releaseOpen = ref(false);
const processing = ref(false);

function askRelease(row: Row) {
    releasing.value = row;
    releaseOpen.value = true;
}

function release() {
    const row = releasing.value;

    if (!row || processing.value) {
        return;
    }

    router.post(
        (row.type === 'quiz' ? releaseQuiz : releaseAssignment).url([
            slug(),
            row.id,
        ]),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => {
                processing.value = false;
                releaseOpen.value = false;
            },
            onError: () => toast.error('Results could not be released.'),
        },
    );
}
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div
        v-if="overview && currentTeam"
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
    >
        <div class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ currentTeam.name }}
            </h1>
            <p class="text-sm text-muted-foreground">
                What's happening in your course.
            </p>
        </div>

        <section
            v-if="overview.isEmpty"
            class="rounded-xl border border-dashed p-6"
        >
            <h2 class="font-medium">Get started</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Three steps to your first quiz.
            </p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <Link
                    :href="studentsIndex(slug())"
                    class="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/50"
                >
                    <Users class="mt-0.5 size-5 text-muted-foreground" />
                    <span>
                        <span class="block text-sm font-medium">
                            1. Add students
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{
                                overview.hasStudents
                                    ? 'Done — add more any time.'
                                    : 'Type them in or import a CSV.'
                            }}
                        </span>
                    </span>
                </Link>
                <Link
                    :href="createQuestion(slug())"
                    class="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/50"
                >
                    <Library class="mt-0.5 size-5 text-muted-foreground" />
                    <span>
                        <span class="block text-sm font-medium">
                            2. Create questions
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{
                                overview.hasQuestions
                                    ? 'Done — your bank has questions.'
                                    : 'Write them or generate them with AI.'
                            }}
                        </span>
                    </span>
                </Link>
                <Link
                    :href="createQuiz(slug())"
                    class="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-muted/50"
                >
                    <ClipboardCheck
                        class="mt-0.5 size-5 text-muted-foreground"
                    />
                    <span>
                        <span class="block text-sm font-medium">
                            3. Create a quiz
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Pick questions, set a time, share codes.
                        </span>
                    </span>
                </Link>
            </div>
        </section>

        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                :href="reviewIndex(slug())"
                class="flex items-center justify-between rounded-xl border p-4 transition-colors hover:bg-muted/50"
            >
                <span class="flex items-center gap-3">
                    <Inbox class="size-5 text-muted-foreground" />
                    <span>
                        <span class="block text-sm font-medium"
                            >Needs review</span
                        >
                        <span class="block text-xs text-muted-foreground">
                            AI grades waiting for your decision
                        </span>
                    </span>
                </span>
                <span
                    :class="[
                        'text-2xl font-semibold tabular-nums',
                        overview.needsReview > 0
                            ? 'text-amber-600 dark:text-amber-400'
                            : '',
                    ]"
                >
                    {{ overview.needsReview }}
                </span>
            </Link>
            <Link
                :href="aiUsage(slug())"
                class="flex items-center justify-between rounded-xl border p-4 transition-colors hover:bg-muted/50"
            >
                <span class="flex items-center gap-3">
                    <Sparkles class="size-5 text-muted-foreground" />
                    <span>
                        <span class="block text-sm font-medium">
                            AI spend this month
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Generation and grading
                        </span>
                    </span>
                </span>
                <span class="text-2xl font-semibold tabular-nums">
                    ${{ overview.aiSpendMonth.toFixed(2) }}
                </span>
            </Link>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium">
                    <span class="size-2 rounded-full bg-emerald-500" /> Open now
                </h2>
                <p
                    v-if="overview.active.length === 0"
                    class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    Nothing is open right now.
                </p>
                <ul v-else class="divide-y rounded-xl border">
                    <li v-for="row in overview.active" :key="row.id">
                        <Link
                            :href="rowHref(row)"
                            class="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-muted/40"
                        >
                            <span class="min-w-0">
                                <span
                                    class="flex items-center gap-1.5 truncate text-sm font-medium"
                                >
                                    <component
                                        :is="
                                            row.type === 'quiz'
                                                ? ClipboardCheck
                                                : FolderGit2
                                        "
                                        class="size-4 shrink-0 text-muted-foreground"
                                    />
                                    {{ row.title }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ row.type === 'quiz' ? 'Closes' : 'Due' }}
                                    {{ formatInCourseTz(row.closes_at) }}
                                </span>
                            </span>
                            <span
                                class="shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                            >
                                <span
                                    class="block text-sm font-medium text-foreground"
                                >
                                    {{ row.submitted }} / {{ row.participants }}
                                </span>
                                {{
                                    row.type === 'quiz'
                                        ? `submitted · ${row.started} started`
                                        : 'submitted'
                                }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium">
                    <CalendarClock class="size-4 text-muted-foreground" />
                    Coming up (next 14 days)
                </h2>
                <p
                    v-if="overview.upcoming.length === 0"
                    class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    Nothing scheduled.
                </p>
                <ul v-else class="divide-y rounded-xl border">
                    <li
                        v-for="row in overview.upcoming"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                    >
                        <Link
                            :href="
                                row.type === 'quiz'
                                    ? results([slug(), row.id])
                                    : assignmentEdit([slug(), row.id])
                            "
                            class="truncate font-medium hover:underline"
                        >
                            {{ row.title }}
                        </Link>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            Opens {{ formatInCourseTz(row.opens_at) }}
                        </span>
                    </li>
                </ul>
            </section>

            <section v-if="overview.unreleased.length" class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium">
                    <Send class="size-4 text-muted-foreground" />
                    Closed — results not released
                </h2>
                <ul class="divide-y rounded-xl border">
                    <li
                        v-for="row in overview.unreleased"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                    >
                        <Link
                            :href="
                                row.type === 'quiz'
                                    ? results([slug(), row.id])
                                    : submissions([slug(), row.id])
                            "
                            class="min-w-0 truncate font-medium hover:underline"
                        >
                            {{ row.title }}
                            <span
                                class="block text-xs font-normal text-muted-foreground"
                            >
                                {{ row.submitted }} submitted
                            </span>
                        </Link>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="askRelease(row)"
                        >
                            Release
                        </Button>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium">
                    <History class="size-4 text-muted-foreground" />
                    Recent activity
                </h2>
                <p
                    v-if="overview.activity.length === 0"
                    class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    Grade changes, resets and releases will show up here.
                </p>
                <ul v-else class="divide-y rounded-xl border text-sm">
                    <li
                        v-for="entry in overview.activity"
                        :key="entry.id"
                        class="flex items-center justify-between gap-3 px-4 py-2.5"
                    >
                        <span class="min-w-0 truncate">
                            <span class="font-medium">{{
                                entry.user ?? 'Someone'
                            }}</span>
                            · {{ auditActionLabel(entry.action) }}
                        </span>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            {{ formatInCourseTz(entry.created_at) }}
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <div v-if="!overview.isEmpty" class="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" as-child>
                <Link :href="createQuiz(slug())"><Plus /> New quiz</Link>
            </Button>
            <Button variant="outline" size="sm" as-child>
                <Link :href="createQuestion(slug())"
                    ><Plus /> New question</Link
                >
            </Button>
        </div>
        <ConfirmDialog
            v-model:open="releaseOpen"
            title="Release results?"
            :description="`Students of “${releasing?.title ?? ''}” can open their results links and see every published grade.`"
            confirm-label="Release"
            :processing="processing"
            @confirm="release"
        />
    </div>
</template>
