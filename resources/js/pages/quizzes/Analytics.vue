<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BarChart3, Check, TriangleAlert } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Markdown from '@/components/markdown/Markdown.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { Badge } from '@/components/ui/badge';
import { marks } from '@/lib/grading';
import { cn } from '@/lib/utils';
import { index } from '@/routes/quizzes';
import type { QuizShellProps, Team } from '@/types';

type Stat = {
    id: number;
    position: number;
    type: string;
    excerpt: string;
    marks: number;
    graded: number;
    answered: number;
    average_percent: number | null;
    full_marks_percent: number | null;
    flag: 'hard' | 'easy' | null;
    options:
        | { body: string; correct: boolean; count: number; percent: number }[]
        | null;
    overridden: number | null;
};

defineProps<QuizShellProps & { graded: number; questions: Stat[] }>();

defineOptions({
    layout: (props: { currentTeam: Team } & QuizShellProps) => ({
        breadcrumbs: [
            { title: 'Quizzes', href: index(props.currentTeam.slug) },
            { title: props.quiz.title, href: '#' },
        ],
    }),
});

const percent = (value: number | null) =>
    value === null ? '—' : `${Math.round(value)}%`;
</script>

<template>
    <Head :title="`${quiz.title} · Analytics`" />

    <QuizShell :quiz="quiz" :checklist="checklist" tab="analytics">
        <EmptyState
            v-if="graded === 0"
            :icon="BarChart3"
            title="No graded attempts yet"
            description="Once attempts are graded you'll see how each question performed: average score, how many got full marks, and which options students picked."
        />

        <template v-else>
            <p class="text-sm text-muted-foreground">
                Based on {{ graded }} graded
                {{ graded === 1 ? 'attempt' : 'attempts' }}. Questions where
                fewer than 30% or more than 95% of students got full marks are
                flagged for a second look.
            </p>

            <ol class="space-y-4">
                <li
                    v-for="stat in questions"
                    :key="stat.id"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-semibold">
                                Q{{ stat.position }}
                                <span class="font-normal text-muted-foreground">
                                    · {{ marks(stat.marks) }} marks
                                </span>
                            </p>
                            <p class="text-sm">{{ stat.excerpt }}</p>
                        </div>
                        <Badge v-if="stat.flag" variant="warning">
                            <TriangleAlert class="size-3" />
                            {{
                                stat.flag === 'hard'
                                    ? 'Few got full marks — check it'
                                    : 'Almost everyone got it'
                            }}
                        </Badge>
                    </div>

                    <dl class="grid grid-cols-3 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Average score
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ percent(stat.average_percent) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Full marks
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ percent(stat.full_marks_percent) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{
                                    stat.overridden !== null
                                        ? 'AI grade changed'
                                        : 'Answered'
                                }}
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{
                                    stat.overridden !== null
                                        ? stat.overridden
                                        : `${stat.answered} / ${stat.graded}`
                                }}
                            </dd>
                        </div>
                    </dl>

                    <ul v-if="stat.options" class="space-y-1.5">
                        <li
                            v-for="(option, i) in stat.options"
                            :key="i"
                            class="space-y-1"
                        >
                            <div
                                class="flex items-center justify-between gap-3 text-sm"
                            >
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <Check
                                        v-if="option.correct"
                                        class="size-4 shrink-0 text-emerald-600"
                                        aria-label="Correct option"
                                    />
                                    <span v-else class="size-4 shrink-0" />
                                    <Markdown
                                        :source="option.body"
                                        inline
                                        class="truncate"
                                    />
                                </span>
                                <span
                                    class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ option.count }} ·
                                    {{ percent(option.percent) }}
                                </span>
                            </div>
                            <div
                                class="ml-5.5 h-2 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    :class="
                                        cn(
                                            'h-full rounded-full',
                                            option.correct
                                                ? 'bg-emerald-500'
                                                : 'bg-muted-foreground/40',
                                        )
                                    "
                                    :style="{ width: `${option.percent}%` }"
                                />
                            </div>
                        </li>
                    </ul>
                </li>
            </ol>
        </template>
    </QuizShell>
</template>
