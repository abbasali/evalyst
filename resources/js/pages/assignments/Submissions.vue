<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Eye,
    EyeOff,
    GitCommitHorizontal,
    RotateCcw,
    Send,
    SlidersHorizontal,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import OverridesDialog from '@/components/assignments/OverridesDialog.vue';
import AssignmentShell from '@/components/assignments/AssignmentShell.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CopyButton from '@/components/CopyButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import GradeStatusBadge from '@/components/grading/GradeStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { marks } from '@/lib/grading';
import { index } from '@/routes/assignments';
import {
    release as releaseRoute,
    unrelease,
} from '@/routes/assignments/results';
import { regrade as regradeAll } from '@/routes/assignments/submissions';
import { show as reviewSubmission } from '@/routes/review/submissions';
import type {
    AssignmentShellProps,
    Option,
    ParticipantOverrides,
    Team,
} from '@/types';

type Row = {
    id: number;
    name: string;
    roll_number: string;
    results_url: string;
    submission: {
        id: number;
        repo_url: string;
        commit_url: string;
        short_sha: string;
        submitted_at: string;
        minutes_late: number;
        status: string;
        raw_score: number | null;
        penalty: number;
        score: number | null;
        max_score: number;
    } | null;
    has_overrides: boolean;
    overrides: ParticipantOverrides;
};

const props = defineProps<
    AssignmentShellProps & {
        rows: Row[];
        summary: {
            participants: number;
            submitted: number;
            late: number;
            graded: number;
        };
        release: {
            mode: 'manual' | 'automatic';
            released: boolean;
            released_at: string | null;
        };
        lateOverrides: Option[];
    }
>();

defineOptions({
    layout: (props: { currentTeam: Team } & AssignmentShellProps) => ({
        breadcrumbs: [
            { title: 'Assignments', href: index(props.currentTeam.slug) },
            { title: props.assignment.title, href: '#' },
        ],
    }),
});

const { slug } = useCourse();
const args = computed(
    () => [slug.value, props.assignment.id] as [string, number],
);

const filter = ref<'' | 'submitted' | 'late' | 'missing' | 'needs_review'>('');
const visibleRows = computed(() =>
    props.rows.filter((row) => {
        switch (filter.value) {
            case 'submitted':
                return row.submission !== null;
            case 'late':
                return (row.submission?.minutes_late ?? 0) > 0;
            case 'missing':
                return row.submission === null;
            case 'needs_review':
                return (
                    row.submission?.status === 'needs_review' ||
                    row.submission?.status === 'failed'
                );
            default:
                return true;
        }
    }),
);

function lateLabel(minutes: number): string {
    if (minutes < 60) {
        return `${minutes}m late`;
    }

    if (minutes < 1440) {
        return `${Math.floor(minutes / 60)}h ${minutes % 60}m late`;
    }

    return `${Math.floor(minutes / 1440)}d ${Math.floor((minutes % 1440) / 60)}h late`;
}

const editing = ref<Row | null>(null);
const overridesOpen = ref(false);

function openOverrides(row: Row) {
    editing.value = row;
    overridesOpen.value = true;
}

const stats = computed(() => [
    { label: 'Students', value: props.summary.participants },
    { label: 'Submitted', value: props.summary.submitted },
    { label: 'Late', value: props.summary.late },
    { label: 'Graded', value: props.summary.graded },
]);

const regradeOpen = ref(false);

function confirmRegradeAll() {
    router.post(
        regradeAll.url(args.value),
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

const confirmOpen = ref(false);
const processing = ref(false);

function toggleRelease() {
    router.post(
        (props.release.released ? unrelease : releaseRoute).url(args.value),
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => {
                processing.value = false;
                confirmOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`${assignment.title} · Submissions`" />

    <AssignmentShell
        :assignment="assignment"
        :checklist="checklist"
        tab="submissions"
    >
        <EmptyState
            v-if="rows.length === 0"
            :icon="Users"
            title="No students yet"
            description="Students appear here once they're added (roster) or join with the shared code."
        />

        <template v-else>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="rounded-xl border px-4 py-3"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ stat.label }}
                    </p>
                    <p class="text-lg font-semibold tabular-nums">
                        {{ stat.value }}
                    </p>
                </div>
            </div>

            <div
                class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="flex items-center gap-2 text-sm font-medium">
                    <component
                        :is="release.released ? Eye : EyeOff"
                        class="size-4"
                    />
                    {{
                        release.released
                            ? 'Results are visible to students'
                            : release.mode === 'automatic'
                              ? 'Results are released automatically after the deadline'
                              : 'Results are hidden from students'
                    }}
                </p>
                <Button
                    v-if="
                        release.mode === 'manual' &&
                        assignment.state !== 'draft'
                    "
                    :variant="release.released ? 'outline' : 'default'"
                    :disabled="processing"
                    @click="confirmOpen = true"
                >
                    <component :is="release.released ? EyeOff : Send" />
                    {{ release.released ? 'Hide results' : 'Release results' }}
                </Button>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <NativeSelect v-model="filter" class="w-48">
                    <option value="">Everyone</option>
                    <option value="submitted">Submitted</option>
                    <option value="late">Late only</option>
                    <option value="missing">Not submitted</option>
                    <option value="needs_review">Needs review</option>
                </NativeSelect>
                <Button
                    v-if="summary.submitted > 0 && assignment.can.update"
                    variant="outline"
                    @click="regradeOpen = true"
                >
                    <RotateCcw /> Regrade all
                </Button>
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Student</th>
                            <th class="px-4 py-3 font-medium">Submission</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Score
                            </th>
                            <th class="w-24 px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in visibleRows"
                            :key="row.id"
                            class="transition-colors hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    v-if="row.submission"
                                    :href="
                                        reviewSubmission([
                                            slug,
                                            row.submission.id,
                                        ])
                                    "
                                    class="font-medium hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                                <span v-else class="font-medium">{{
                                    row.name
                                }}</span>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ row.roll_number }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="row.submission">
                                    <a
                                        :href="row.submission.commit_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-1 font-mono text-xs hover:underline"
                                    >
                                        <GitCommitHorizontal class="size-3.5" />
                                        {{
                                            row.submission.repo_url.replace(
                                                'https://github.com/',
                                                '',
                                            )
                                        }}@{{ row.submission.short_sha }}
                                    </a>
                                    <div class="text-xs text-muted-foreground">
                                        {{
                                            formatInCourseTz(
                                                row.submission.submitted_at,
                                            )
                                        }}
                                        <Badge
                                            v-if="
                                                row.submission.minutes_late > 0
                                            "
                                            variant="warning"
                                            class="ml-1"
                                        >
                                            {{
                                                lateLabel(
                                                    row.submission.minutes_late,
                                                )
                                            }}
                                        </Badge>
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    Not submitted
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <GradeStatusBadge
                                    v-if="row.submission"
                                    :status="row.submission.status"
                                />
                            </td>
                            <td
                                class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                            >
                                <template
                                    v-if="
                                        row.submission?.score !== null &&
                                        row.submission
                                    "
                                >
                                    <span class="font-medium">
                                        {{ marks(row.submission.score) }}
                                    </span>
                                    <span class="text-muted-foreground">
                                        / {{ marks(row.submission.max_score) }}
                                    </span>
                                    <div
                                        v-if="row.submission.penalty > 0"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ marks(row.submission.raw_score) }} −
                                        {{ marks(row.submission.penalty) }}
                                        late
                                    </div>
                                </template>
                                <template v-else-if="row.submission?.penalty">
                                    <span class="text-xs text-muted-foreground">
                                        −{{ marks(row.submission.penalty) }}
                                        late
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </td>
                            <td class="px-2 py-2 text-right whitespace-nowrap">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :class="
                                        row.has_overrides
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : ''
                                    "
                                    :aria-label="`Overrides for ${row.name}`"
                                    @click="openOverrides(row)"
                                >
                                    <SlidersHorizontal />
                                    <span class="sr-only sm:not-sr-only">
                                        {{
                                            row.has_overrides
                                                ? 'Overridden'
                                                : 'Override'
                                        }}
                                    </span>
                                </Button>
                                <CopyButton
                                    :value="row.results_url"
                                    :label="`Copy ${row.name}'s results link`"
                                />
                            </td>
                        </tr>
                        <tr v-if="visibleRows.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Nobody matches this filter.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <OverridesDialog
            v-model:open="overridesOpen"
            :assignment-id="assignment.id"
            :participant="editing"
            :late-overrides="lateOverrides"
        />

        <ConfirmDialog
            v-model:open="regradeOpen"
            title="Regrade every submission?"
            description="Use this after changing the rules. Each current submission is graded again at its submitted commit (AI rules cost a little each). Grades are hidden from students until they're settled again."
            confirm-label="Regrade all"
            :processing="processing"
            @confirm="confirmRegradeAll"
        />
        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="release.released ? 'Hide results?' : 'Release results?'"
            :description="
                release.released
                    ? 'Students will no longer see their scores until you release them again.'
                    : 'Students can open their results links and see every published grade. Submissions still under review show as “Under review”.'
            "
            :confirm-label="release.released ? 'Hide results' : 'Release'"
            :processing="processing"
            @confirm="toggleRelease"
        />
    </AssignmentShell>
</template>
