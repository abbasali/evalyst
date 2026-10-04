<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Clock, GitCommitHorizontal, Hourglass } from '@lucide/vue';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { formatInCourseTz } from '@/lib/datetime';
import { marks } from '@/lib/grading';

defineProps<{
    assessment: { title: string; timezone: string };
    student: { name: string; roll_number: string };
    submission: {
        submitted_at: string;
        short_sha: string;
        commit_url: string;
    } | null;
    released: boolean;
    published: boolean;
    grade: {
        rules: {
            title: string;
            score: number;
            max_score: number;
            reasoning: string | null;
        }[];
        feedback: string | null;
        raw_score: number;
        penalty: number;
        minutes_late: number;
        score: number;
        max_score: number;
    } | null;
}>();

function late(minutes: number): string {
    return minutes < 60
        ? `${minutes} minutes late`
        : minutes < 1440
          ? `${Math.ceil(minutes / 60)} hours late`
          : `${Math.ceil(minutes / 1440)} days late`;
}
</script>

<template>
    <Head :title="`Results · ${assessment.title}`" />

    <StudentLayout :title="assessment.title">
        <div class="mx-auto w-full max-w-3xl space-y-6 py-6">
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"
            >
                <div class="space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ assessment.title }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ student.name }} ·
                        <span class="font-mono">{{ student.roll_number }}</span>
                    </p>
                    <p
                        v-if="submission"
                        class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground"
                    >
                        Submitted
                        {{
                            formatInCourseTz(
                                submission.submitted_at,
                                undefined,
                                assessment.timezone,
                            )
                        }}
                        ·
                        <a
                            :href="submission.commit_url"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 font-mono hover:underline"
                        >
                            <GitCommitHorizontal class="size-3.5" />
                            {{ submission.short_sha }}
                        </a>
                    </p>
                </div>
                <div
                    v-if="grade"
                    class="rounded-xl border bg-background px-5 py-3 sm:text-right"
                >
                    <p class="text-xs text-muted-foreground">Final score</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ marks(grade.score) }}
                        <span
                            class="text-base font-normal text-muted-foreground"
                        >
                            / {{ marks(grade.max_score) }}
                        </span>
                    </p>
                </div>
            </div>

            <div
                v-if="!grade"
                class="flex flex-col items-center gap-3 rounded-xl border bg-background px-6 py-12 text-center"
            >
                <component
                    :is="released && submission ? Clock : Hourglass"
                    class="size-8 text-muted-foreground"
                />
                <p class="font-medium">
                    {{
                        !submission
                            ? 'You haven’t submitted this assignment.'
                            : released
                              ? 'Under review.'
                              : 'Results are not available yet.'
                    }}
                </p>
                <p
                    v-if="submission"
                    class="max-w-sm text-sm text-muted-foreground"
                >
                    Keep this link and check back later.
                </p>
            </div>

            <template v-else>
                <section class="space-y-2 rounded-xl border bg-background p-5">
                    <h2 class="font-medium">Breakdown</h2>
                    <ul class="divide-y text-sm">
                        <li
                            v-for="(rule, i) in grade.rules"
                            :key="i"
                            class="space-y-1 py-3"
                        >
                            <div class="flex justify-between gap-3">
                                <span class="font-medium">{{
                                    rule.title
                                }}</span>
                                <span class="shrink-0 tabular-nums">
                                    {{ marks(rule.score) }} /
                                    {{ marks(rule.max_score) }}
                                </span>
                            </div>
                            <p
                                v-if="rule.reasoning"
                                class="text-muted-foreground"
                            >
                                {{ rule.reasoning }}
                            </p>
                        </li>
                    </ul>
                    <div class="space-y-1 border-t pt-3 text-sm">
                        <div
                            v-if="grade.penalty > 0"
                            class="flex justify-between"
                        >
                            <span class="text-muted-foreground">
                                Late penalty ({{ late(grade.minutes_late) }})
                            </span>
                            <span class="tabular-nums">
                                {{ marks(grade.raw_score) }} −
                                {{ marks(grade.penalty) }}
                            </span>
                        </div>
                        <div class="flex justify-between font-semibold">
                            <span>Final score</span>
                            <span class="tabular-nums">
                                {{ marks(grade.score) }} /
                                {{ marks(grade.max_score) }}
                            </span>
                        </div>
                    </div>
                </section>

                <section
                    v-if="grade.feedback"
                    class="space-y-2 rounded-xl border bg-background p-5"
                >
                    <h2 class="font-medium">Feedback</h2>
                    <p class="text-sm whitespace-pre-wrap">
                        {{ grade.feedback }}
                    </p>
                </section>
            </template>
        </div>
    </StudentLayout>
</template>
