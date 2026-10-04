<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FolderGit2, KeyRound, Plus, Users } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import QuizStateBadge from '@/components/quizzes/QuizStateBadge.vue';
import { Button } from '@/components/ui/button';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { create, edit, index, rules } from '@/routes/assignments';
import type { AssignmentRow, Paginated, Team } from '@/types';

type Tab = 'open' | 'upcoming' | 'draft' | 'closed' | 'archived';

const props = defineProps<{
    assignments: Paginated<AssignmentRow>;
    tab: Tab;
    counts: Record<Tab, number>;
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            { title: 'Assignments', href: index(props.currentTeam.slug) },
        ],
    }),
});

const { slug } = useCourse();

const tabs: { key: Tab; label: string }[] = [
    { key: 'open', label: 'Open' },
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'draft', label: 'Drafts' },
    { key: 'closed', label: 'Closed' },
    { key: 'archived', label: 'Archived' },
];

const total = computed(() =>
    Object.values(props.counts).reduce((sum, count) => sum + count, 0),
);

const emptyText: Record<Tab, string> = {
    open: 'No assignment is open right now.',
    upcoming: 'Nothing scheduled yet.',
    draft: 'No drafts. Create an assignment to start one.',
    closed: 'No closed assignments yet.',
    archived: 'Nothing archived.',
};

/** Drafts without rules go straight to the Rules tab. */
function rowHref(assignment: AssignmentRow) {
    return assignment.state === 'draft' && assignment.rules_count === 0
        ? rules([slug.value, assignment.id])
        : edit([slug.value, assignment.id]);
}
</script>

<template>
    <Head title="Assignments" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Assignments"
            description="Projects students build on GitHub. They submit a public repo, which is graded by automated checks and AI rules."
        >
            <template #actions>
                <Button as-child>
                    <Link :href="create(slug)"><Plus /> New assignment</Link>
                </Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="total === 0"
            :icon="FolderGit2"
            title="No assignments yet"
            description="Write the problem statement, set a deadline and late policy, then add grading rules."
        >
            <Button as-child>
                <Link :href="create(slug)"><Plus /> New assignment</Link>
            </Button>
        </EmptyState>

        <template v-else>
            <nav
                class="-mb-px flex gap-1 overflow-x-auto border-b"
                aria-label="Assignment status"
            >
                <Link
                    v-for="item in tabs"
                    :key="item.key"
                    :href="index.url(slug, { query: { tab: item.key } })"
                    preserve-scroll
                    :class="
                        cn(
                            'flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                            tab === item.key
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )
                    "
                >
                    {{ item.label }}
                    <span
                        class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
                    >
                        {{ counts[item.key] }}
                    </span>
                </Link>
            </nav>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Assignment</th>
                            <th
                                class="hidden px-4 py-3 font-medium md:table-cell"
                            >
                                Schedule
                            </th>
                            <th
                                class="hidden px-4 py-3 font-medium sm:table-cell"
                            >
                                Rules
                            </th>
                            <th class="px-4 py-3 font-medium">Students</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="quiz in assignments.data"
                            :key="quiz.id"
                            class="transition-colors hover:bg-muted/30"
                        >
                            <td class="px-4 py-3">
                                <Link
                                    :href="rowHref(quiz)"
                                    class="font-medium hover:underline"
                                >
                                    {{ quiz.title }}
                                </Link>
                                <div class="mt-1 flex items-center gap-2">
                                    <QuizStateBadge :state="quiz.state" />
                                    <span
                                        class="flex items-center gap-1 text-xs text-muted-foreground"
                                    >
                                        <component
                                            :is="
                                                quiz.access_mode === 'roster'
                                                    ? Users
                                                    : KeyRound
                                            "
                                            class="size-3"
                                        />
                                        {{
                                            quiz.access_mode === 'roster'
                                                ? 'Roster codes'
                                                : 'Shared code'
                                        }}
                                    </span>
                                </div>
                            </td>
                            <td
                                class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                            >
                                <div v-if="quiz.opens_at">
                                    Opens {{ formatInCourseTz(quiz.opens_at) }}
                                </div>
                                <div>
                                    Due
                                    {{ formatInCourseTz(quiz.closes_at) }}
                                </div>
                            </td>
                            <td
                                class="hidden px-4 py-3 text-muted-foreground sm:table-cell"
                            >
                                {{ quiz.rules_count }}
                                <span class="text-xs">
                                    · {{ quiz.max_score }} marks
                                </span>
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                <span class="font-medium text-foreground">
                                    {{ quiz.participants_count }}
                                </span>
                                <div
                                    v-if="quiz.state !== 'draft'"
                                    class="text-xs"
                                >
                                    {{ quiz.submitted_count }} submitted
                                </div>
                            </td>
                        </tr>
                        <tr v-if="assignments.data.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                {{ emptyText[tab] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="assignments" noun="assignments" />
        </template>
    </div>
</template>
