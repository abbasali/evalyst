<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCheck, Inbox, PartyPopper } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { marks, reasonLabel } from '@/lib/grading';
import { cn } from '@/lib/utils';
import { bulkAccept, index } from '@/routes/review';
import { show } from '@/routes/review/answers';
import { show as showSubmission } from '@/routes/review/submissions';
import type {
    Paginated,
    ReviewFilters,
    ReviewRow,
    SubmissionReviewRow,
    Team,
} from '@/types';

const props = defineProps<{
    rows: Paginated<ReviewRow>;
    submissions: SubmissionReviewRow[];
    submissionsTotal: number;
    filters: ReviewFilters;
    counts: { needs_review: number; failed: number };
    assessments: { id: number; title: string }[];
    questions: { id: number; label: string }[];
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [{ title: 'Review', href: index(props.currentTeam.slug) }],
    }),
});

const { slug } = useCourse();
const total = computed(() => props.counts.needs_review + props.counts.failed);

const statusTabs = computed(() => [
    { value: undefined, label: 'All', count: total.value },
    {
        value: 'needs_review',
        label: 'Needs review',
        count: props.counts.needs_review,
    },
    { value: 'failed', label: 'Failed', count: props.counts.failed },
]);

const reasons = [
    { value: 'low_confidence', label: 'Low confidence' },
    { value: 'flag:prompt_injection', label: 'Tried to instruct the AI' },
    { value: 'flag:off_topic', label: 'Off topic' },
    { value: 'flag:possibly_ai_generated', label: 'Possibly AI-written' },
    { value: 'flag:rubric_ambiguous', label: 'Rubric unclear' },
    { value: 'failed', label: 'Grading failed' },
];

function applyFilters(changes: Partial<ReviewFilters>) {
    const next = { ...props.filters, ...changes };

    if (changes.assessment !== undefined) {
        next.question = undefined;
    }

    router.get(
        index.url(slug.value, {
            query: Object.fromEntries(
                Object.entries(next).filter(([, value]) => value),
            ),
        }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const grouped = computed(() => props.filters.group === '1');

function detailUrl(row: ReviewRow) {
    return show.url([slug.value, row.id], { query: { ...props.filters } });
}

// Bulk accept: only rows with an AI score can be accepted.
const selected = ref<number[]>([]);
const acceptable = computed(() =>
    props.rows.data.filter(
        (row) => row.status === 'needs_review' && row.ai_score !== null,
    ),
);
const allSelected = computed(
    () =>
        acceptable.value.length > 0 &&
        acceptable.value.every((row) => selected.value.includes(row.id)),
);

watch(
    () => props.rows.data,
    () => {
        selected.value = selected.value.filter((id) =>
            acceptable.value.some((row) => row.id === id),
        );
    },
);

function toggleAll(value: boolean | 'indeterminate') {
    selected.value =
        value === true ? acceptable.value.map((row) => row.id) : [];
}

function toggle(id: number, value: boolean | 'indeterminate') {
    selected.value =
        value === true
            ? [...selected.value, id]
            : selected.value.filter((selectedId) => selectedId !== id);
}

const accepting = ref(false);

function acceptSelected() {
    router.post(
        bulkAccept.url(slug.value),
        { ids: selected.value },
        {
            preserveScroll: true,
            onStart: () => (accepting.value = true),
            onFinish: () => (accepting.value = false),
            onSuccess: () => (selected.value = []),
        },
    );
}

function waitingFor(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 60) {
        return `${minutes}m`;
    }

    return minutes < 60 * 48
        ? `${Math.round(minutes / 60)}h`
        : `${Math.round(minutes / 1440)}d`;
}
</script>

<template>
    <Head title="Review" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Review"
            description="AI grades that weren't confident enough to publish on their own, and anything that failed. Students see a grade only after you decide."
        />

        <EmptyState
            v-if="total === 0"
            :icon="PartyPopper"
            title="All caught up"
            description="Nothing needs your review. Confident AI grades are published automatically."
        />

        <template v-else>
            <div class="flex flex-col gap-3">
                <nav class="flex gap-1 border-b" aria-label="Status">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.label"
                        type="button"
                        :class="
                            cn(
                                '-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium',
                                filters.status === tab.value
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="applyFilters({ status: tab.value })"
                    >
                        {{ tab.label }}
                        <span
                            class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </nav>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="grid gap-1">
                        <Label for="assessment" class="text-xs"
                            >Assessment</Label
                        >
                        <NativeSelect
                            id="assessment"
                            :model-value="filters.assessment ?? ''"
                            class="w-56"
                            @update:model-value="
                                applyFilters({
                                    assessment: String($event) || undefined,
                                })
                            "
                        >
                            <option value="">All assessments</option>
                            <option
                                v-for="assessment in assessments"
                                :key="assessment.id"
                                :value="String(assessment.id)"
                            >
                                {{ assessment.title }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div v-if="filters.assessment" class="grid gap-1">
                        <Label for="question" class="text-xs">Question</Label>
                        <NativeSelect
                            id="question"
                            :model-value="filters.question ?? ''"
                            class="w-64"
                            @update:model-value="
                                applyFilters({
                                    question: String($event) || undefined,
                                })
                            "
                        >
                            <option value="">All questions</option>
                            <option
                                v-for="question in questions"
                                :key="question.id"
                                :value="String(question.id)"
                            >
                                {{ question.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label for="reason" class="text-xs">Reason</Label>
                        <NativeSelect
                            id="reason"
                            :model-value="filters.reason ?? ''"
                            class="w-52"
                            @update:model-value="
                                applyFilters({
                                    reason: String($event) || undefined,
                                })
                            "
                        >
                            <option value="">Any reason</option>
                            <option
                                v-for="reason in reasons"
                                :key="reason.value"
                                :value="reason.value"
                            >
                                {{ reason.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <Label class="flex h-9 items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="grouped"
                            @update:model-value="
                                applyFilters({
                                    group: $event === true ? '1' : undefined,
                                })
                            "
                        />
                        Group by question
                    </Label>
                </div>
            </div>

            <section v-if="submissions.length" class="space-y-2">
                <h2 class="text-sm font-medium">
                    Assignment submissions
                    <span class="text-muted-foreground">
                        ({{ submissionsTotal
                        }}<template v-if="submissionsTotal > submissions.length"
                            >, oldest {{ submissions.length }} shown</template
                        >)
                    </span>
                </h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <tbody class="divide-y">
                            <tr
                                v-for="row in submissions"
                                :key="row.id"
                                class="transition-colors hover:bg-muted/30"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="
                                            showSubmission.url([slug, row.id], {
                                                query: { ...filters },
                                            })
                                        "
                                        class="font-medium hover:underline"
                                    >
                                        {{ row.student.name }}
                                    </Link>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.student.roll_number }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs text-muted-foreground">
                                        {{ row.assessment.title }}
                                    </span>
                                    <div class="font-mono text-xs">
                                        {{ row.repo }}
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
                                    <span v-else class="text-muted-foreground">
                                        —
                                    </span>
                                </td>
                                <td class="hidden px-4 py-3 md:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="reason in row.reasons"
                                            :key="reason"
                                            :variant="
                                                reason === 'failed' ||
                                                reason ===
                                                    'flag:prompt_injection'
                                                    ? 'destructive'
                                                    : 'warning'
                                            "
                                        >
                                            {{ reasonLabel(reason) }}
                                        </Badge>
                                    </div>
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-right text-muted-foreground tabular-nums sm:table-cell"
                                >
                                    {{ waitingFor(row.waiting_since) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h2 v-if="rows.data.length" class="pt-2 text-sm font-medium">
                    Quiz answers
                </h2>
            </section>

            <template v-if="rows.data.length || submissions.length === 0">
                <div
                    v-if="selected.length"
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 px-4 py-2 text-sm"
                >
                    <span>{{ selected.length }} selected</span>
                    <Button
                        size="sm"
                        :disabled="accepting"
                        @click="acceptSelected"
                    >
                        <Spinner v-if="accepting" />
                        <CheckCheck v-else /> Accept AI grades
                    </Button>
                </div>

                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="w-10 px-4 py-3">
                                    <Checkbox
                                        :model-value="allSelected"
                                        :disabled="acceptable.length === 0"
                                        aria-label="Select all with an AI grade"
                                        @update:model-value="toggleAll"
                                    />
                                </th>
                                <th class="px-2 py-3 font-medium">Student</th>
                                <th class="px-4 py-3 font-medium">Question</th>
                                <th class="px-4 py-3 text-right font-medium">
                                    AI score
                                </th>
                                <th
                                    class="hidden px-4 py-3 font-medium md:table-cell"
                                >
                                    Why
                                </th>
                                <th
                                    class="hidden px-4 py-3 text-right font-medium sm:table-cell"
                                >
                                    Waiting
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr
                                v-for="row in rows.data"
                                :key="row.id"
                                class="transition-colors hover:bg-muted/30"
                            >
                                <td class="px-4 py-3">
                                    <Checkbox
                                        :model-value="selected.includes(row.id)"
                                        :disabled="
                                            row.status !== 'needs_review' ||
                                            row.ai_score === null
                                        "
                                        :aria-label="`Select ${row.student.name}`"
                                        @update:model-value="
                                            toggle(row.id, $event)
                                        "
                                    />
                                </td>
                                <td class="px-2 py-3">
                                    <Link
                                        :href="detailUrl(row)"
                                        class="font-medium hover:underline"
                                    >
                                        {{ row.student.name }}
                                    </Link>
                                    <div
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ row.student.roll_number }}
                                    </div>
                                </td>
                                <td class="max-w-md px-4 py-3">
                                    <Link :href="detailUrl(row)" class="block">
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ row.assessment.title }} · Q{{
                                                row.question.position
                                            }}
                                        </span>
                                        <span class="line-clamp-1">
                                            {{ row.question.excerpt }}
                                        </span>
                                    </Link>
                                </td>
                                <td
                                    class="px-4 py-3 text-right whitespace-nowrap"
                                >
                                    <template v-if="row.ai_score !== null">
                                        <span class="font-medium tabular-nums">
                                            {{ marks(row.ai_score) }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            / {{ marks(row.max_score) }}
                                        </span>
                                        <div
                                            v-if="row.confidence !== null"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                Math.round(
                                                    row.confidence * 100,
                                                )
                                            }}% sure
                                        </div>
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="hidden px-4 py-3 md:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <Badge
                                            v-for="reason in row.reasons"
                                            :key="reason"
                                            :variant="
                                                reason === 'failed' ||
                                                reason ===
                                                    'flag:prompt_injection'
                                                    ? 'destructive'
                                                    : 'warning'
                                            "
                                        >
                                            {{ reasonLabel(reason) }}
                                        </Badge>
                                    </div>
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-right text-muted-foreground tabular-nums sm:table-cell"
                                >
                                    {{ waitingFor(row.waiting_since) }}
                                </td>
                            </tr>
                            <tr v-if="rows.data.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-10 text-center text-muted-foreground"
                                >
                                    <Inbox class="mx-auto mb-2 size-5" />
                                    Nothing matches these filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :paginator="rows" noun="items" />
            </template>
        </template>
    </div>
</template>
