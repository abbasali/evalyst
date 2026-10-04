<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, Eye, EyeOff, Inbox, Link2, Send, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CopyButton from '@/components/CopyButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import GradeStatusBadge from '@/components/grading/GradeStatusBadge.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { marks } from '@/lib/grading';
import { exportMethod } from '@/routes/assessments';
import { show as attemptShow } from '@/routes/attempts';
import { index } from '@/routes/quizzes';
import { release as releaseRoute, unrelease } from '@/routes/quizzes/results';
import { index as reviewIndex } from '@/routes/review';
import type { QuizShellProps, Team } from '@/types';

type Row = {
    id: number;
    name: string;
    roll_number: string;
    results_url: string;
    attempt_id: number | null;
    status: string;
    score: number | null;
    max_score: number | null;
    submitted_at: string | null;
    auto_submitted: boolean;
    focus_lost: number;
    waiting: number;
};

const props = defineProps<
    QuizShellProps & {
        rows: Row[];
        summary: {
            participants: number;
            submitted: number;
            graded: number;
            waiting: number;
            average: number | null;
        };
        release: {
            mode: 'manual' | 'automatic';
            released: boolean;
            released_at: string | null;
            show_answers: boolean;
        };
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
const args = computed(() => [slug.value, props.quiz.id] as [string, number]);

const statusFilter = ref('');
const sortBy = ref<'roll_number' | 'name' | 'score' | 'submitted_at'>(
    'roll_number',
);

const visibleRows = computed(() => {
    const rows = statusFilter.value
        ? props.rows.filter((row) => row.status === statusFilter.value)
        : [...props.rows];

    return rows.sort((a, b) => {
        switch (sortBy.value) {
            case 'name':
                return a.name.localeCompare(b.name);
            case 'score':
                return (b.score ?? -1) - (a.score ?? -1);
            case 'submitted_at':
                return (a.submitted_at ?? '~').localeCompare(
                    b.submitted_at ?? '~',
                );
            default:
                return a.roll_number.localeCompare(b.roll_number, undefined, {
                    numeric: true,
                });
        }
    });
});

const stats = computed(() => [
    { label: 'Students', value: props.summary.participants },
    { label: 'Submitted', value: props.summary.submitted },
    { label: 'Graded', value: props.summary.graded },
    {
        label: 'Average',
        value:
            props.summary.average === null
                ? '—'
                : `${marks(props.summary.average)} / ${marks(props.quiz.max_score)}`,
    },
]);

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
    <Head :title="`${quiz.title} · Results`" />

    <QuizShell :quiz="quiz" :checklist="checklist" tab="results">
        <EmptyState
            v-if="rows.length === 0"
            :icon="Users"
            title="No students yet"
            description="Results appear here once students join and submit."
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
                <div class="space-y-0.5 text-sm">
                    <p class="flex items-center gap-2 font-medium">
                        <component
                            :is="release.released ? Eye : EyeOff"
                            class="size-4"
                        />
                        {{
                            release.released
                                ? 'Results are visible to students'
                                : 'Results are hidden from students'
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        <template v-if="release.mode === 'automatic'">
                            Released automatically once the quiz closes and
                            everyone has finished.
                        </template>
                        <template v-else-if="release.released">
                            Released
                            {{ formatInCourseTz(release.released_at) }}. Answers
                            still under review show as “Under review”.
                        </template>
                        <template v-else>
                            Students see only published grades, and
                            {{
                                release.show_answers
                                    ? 'the correct answers and explanations'
                                    : 'not the correct answers'
                            }}.
                        </template>
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button
                        v-if="summary.waiting > 0"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="
                                reviewIndex.url(slug, {
                                    query: { assessment: String(quiz.id) },
                                })
                            "
                        >
                            <Inbox /> Review {{ summary.waiting }}
                        </Link>
                    </Button>
                    <Button
                        v-if="
                            release.mode === 'manual' && quiz.state !== 'draft'
                        "
                        :variant="release.released ? 'outline' : 'default'"
                        :disabled="processing"
                        @click="confirmOpen = true"
                    >
                        <component :is="release.released ? EyeOff : Send" />
                        {{
                            release.released
                                ? 'Hide results'
                                : 'Release results'
                        }}
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <Button variant="outline" as-child class="order-last ml-auto">
                    <a :href="exportMethod.url(args)"
                        ><Download /> Export CSV</a
                    >
                </Button>
                <NativeSelect v-model="statusFilter" class="w-44">
                    <option value="">All statuses</option>
                    <option value="not_started">Not started</option>
                    <option value="in_progress">In progress</option>
                    <option value="grading">Grading</option>
                    <option value="graded">Graded</option>
                </NativeSelect>
                <NativeSelect v-model="sortBy" class="w-44">
                    <option value="roll_number">Sort by roll no.</option>
                    <option value="name">Sort by name</option>
                    <option value="score">Sort by score</option>
                    <option value="submitted_at">Sort by submitted</option>
                </NativeSelect>
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Student</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Score
                            </th>
                            <th
                                class="hidden px-4 py-3 font-medium md:table-cell"
                            >
                                Submitted
                            </th>
                            <th
                                class="hidden px-4 py-3 text-right font-medium sm:table-cell"
                            >
                                Focus lost
                            </th>
                            <th class="w-12 px-4 py-3">
                                <span class="sr-only">Results link</span>
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
                                    v-if="row.attempt_id"
                                    :href="attemptShow([slug, row.attempt_id])"
                                    class="font-medium hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                                <span v-else class="font-medium">
                                    {{ row.name }}
                                </span>
                                <div
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ row.roll_number }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-1">
                                    <GradeStatusBadge :status="row.status" />
                                    <Badge v-if="row.waiting" variant="warning">
                                        {{ row.waiting }} to review
                                    </Badge>
                                </div>
                            </td>
                            <td
                                class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                            >
                                <template v-if="row.score !== null">
                                    <span class="font-medium">
                                        {{ marks(row.score) }}
                                    </span>
                                    <span class="text-muted-foreground">
                                        / {{ marks(row.max_score) }}
                                    </span>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td
                                class="hidden px-4 py-3 whitespace-nowrap text-muted-foreground md:table-cell"
                            >
                                {{ formatInCourseTz(row.submitted_at) }}
                                <Badge
                                    v-if="row.auto_submitted"
                                    variant="outline"
                                    class="ml-1"
                                >
                                    auto
                                </Badge>
                            </td>
                            <td
                                :class="[
                                    'hidden px-4 py-3 text-right tabular-nums sm:table-cell',
                                    row.focus_lost >= 3
                                        ? 'font-medium text-amber-700 dark:text-amber-400'
                                        : 'text-muted-foreground',
                                ]"
                            >
                                {{ row.focus_lost }}
                            </td>
                            <td class="px-2 py-2 text-right">
                                <CopyButton
                                    :value="row.results_url"
                                    :label="`Copy ${row.name}'s results link`"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Link2 class="size-3.5" /> Each student has a private results
                link (also shown when they submit).
            </p>
        </template>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="release.released ? 'Hide results?' : 'Release results?'"
            :description="
                release.released
                    ? 'Students will no longer see their scores until you release them again.'
                    : 'Students can open their results links and see every published grade. Answers still under review show as “Under review” until you publish them.'
            "
            :confirm-label="release.released ? 'Hide results' : 'Release'"
            :processing="processing"
            @confirm="toggleRelease"
        />
    </QuizShell>
</template>
