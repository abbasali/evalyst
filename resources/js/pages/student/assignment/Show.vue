<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    BarChart3,
    CalendarClock,
    CheckCircle2,
    GitCommitHorizontal,
    ListChecks,
    Send,
    Timer,
} from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import CopyButton from '@/components/CopyButton.vue';
import InputError from '@/components/InputError.vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { formatInCourseTz } from '@/lib/datetime';
import { marks } from '@/lib/grading';
import { submit } from '@/routes/student/assignment';

const props = defineProps<{
    assignment: {
        public_id: string;
        title: string;
        instructions: string | null;
        timezone: string;
        opens_at: string | null;
        deadline: string;
        has_deadline_override: boolean;
        late_policy: string;
        max_score: number;
        rules: { title: string; marks: number }[] | null;
    };
    canSubmit: { allowed: boolean; late: boolean; reason: string | null };
    submissions: {
        id: string;
        repo_url: string;
        commit_url: string;
        short_sha: string;
        submitted_at: string;
        minutes_late: number;
        is_current: boolean;
    }[];
    resultsUrl: string;
}>();

const time = (iso: string | null) =>
    formatInCourseTz(iso, undefined, props.assignment.timezone);

const current = computed(() => props.submissions.find((s) => s.is_current));
const form = useForm({ repo_url: current.value?.repo_url ?? '' });

function send() {
    form.submit(submit(props.assignment.public_id), { preserveScroll: true });
}

// A simple countdown to this student's deadline (display only; the server decides).
const now = ref(Date.now());
useIntervalFn(() => (now.value = Date.now()), 30_000);
const countdown = computed(() => {
    const ms = new Date(props.assignment.deadline).getTime() - now.value;

    if (ms <= 0) {
        return null;
    }

    const minutes = Math.floor(ms / 60000);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    return days > 0
        ? `${days}d ${hours}h left`
        : hours > 0
          ? `${hours}h ${minutes % 60}m left`
          : `${minutes}m left`;
});

function lateLabel(minutes: number): string {
    return minutes < 60
        ? `${minutes} min late`
        : minutes < 1440
          ? `${Math.ceil(minutes / 60)} h late`
          : `${Math.ceil(minutes / 1440)} days late`;
}
</script>

<template>
    <Head :title="assignment.title" />

    <StudentLayout :title="assignment.title">
        <div class="mx-auto w-full max-w-3xl space-y-6 py-6">
            <div class="space-y-2">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ assignment.title }}
                </h1>
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                >
                    <span class="flex items-center gap-1.5">
                        <CalendarClock class="size-4 text-muted-foreground" />
                        Due {{ time(assignment.deadline) }}
                        <Badge
                            v-if="assignment.has_deadline_override"
                            variant="info"
                        >
                            your deadline
                        </Badge>
                    </span>
                    <span
                        v-if="countdown"
                        class="flex items-center gap-1.5 text-muted-foreground"
                    >
                        <Timer class="size-4" /> {{ countdown }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ assignment.late_policy }}
                </p>
            </div>

            <section class="space-y-4 rounded-xl border bg-background p-5">
                <h2 class="font-medium">Submit your repository</h2>

                <div
                    v-if="current"
                    class="flex flex-col gap-1 rounded-lg bg-emerald-500/5 p-3 text-sm ring-1 ring-emerald-500/30"
                >
                    <p class="flex items-center gap-1.5 font-medium">
                        <CheckCircle2
                            class="size-4 text-emerald-600 dark:text-emerald-400"
                        />
                        Submitted {{ time(current.submitted_at) }}
                        <Badge
                            v-if="current.minutes_late > 0"
                            variant="warning"
                        >
                            {{ lateLabel(current.minutes_late) }}
                        </Badge>
                    </p>
                    <a
                        :href="current.commit_url"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center gap-1 font-mono text-xs text-muted-foreground hover:underline"
                    >
                        <GitCommitHorizontal class="size-3.5" />
                        {{
                            current.repo_url.replace('https://github.com/', '')
                        }}
                        @ {{ current.short_sha }}
                    </a>
                    <p class="text-xs text-muted-foreground">
                        We grade the code exactly as it was at this commit.
                        Pushing more commits doesn’t change it
                        {{
                            canSubmit.allowed
                                ? '— submit again to update it.'
                                : '.'
                        }}
                    </p>
                </div>

                <form
                    v-if="canSubmit.allowed"
                    class="space-y-2"
                    @submit.prevent="send"
                >
                    <Label for="repo_url">Public GitHub repository URL</Label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id="repo_url"
                            v-model="form.repo_url"
                            type="url"
                            inputmode="url"
                            autocomplete="off"
                            placeholder="https://github.com/your-name/your-project"
                            required
                        />
                        <Button
                            type="submit"
                            class="shrink-0"
                            :disabled="form.processing"
                        >
                            <Spinner v-if="form.processing" />
                            <Send v-else />
                            {{ current ? 'Submit again' : 'Submit' }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.repo_url" />
                    <p
                        v-if="canSubmit.late"
                        class="text-xs text-amber-700 dark:text-amber-400"
                    >
                        The deadline has passed, so this counts as a late
                        submission.
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        The repository must be public. We record its latest
                        commit on the default branch when you submit.
                    </p>
                </form>
                <p
                    v-else-if="canSubmit.reason"
                    class="text-sm text-muted-foreground"
                >
                    {{ canSubmit.reason }}
                </p>

                <div class="space-y-1 border-t pt-3">
                    <p class="text-xs font-medium text-muted-foreground">
                        Your results link
                    </p>
                    <div
                        class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2"
                    >
                        <span class="min-w-0 flex-1 truncate font-mono text-xs">
                            {{ resultsUrl }}
                        </span>
                        <CopyButton
                            :value="resultsUrl"
                            label="Copy results link"
                        />
                    </div>
                    <Button
                        v-if="current"
                        as-child
                        variant="outline"
                        size="sm"
                        class="mt-1"
                    >
                        <a :href="resultsUrl"><BarChart3 /> View my results</a>
                    </Button>
                </div>
            </section>

            <section class="space-y-3 rounded-xl border bg-background p-5">
                <h2 class="font-medium">The assignment</h2>
                <Markdown :source="assignment.instructions" />
            </section>

            <section
                v-if="assignment.rules"
                class="space-y-3 rounded-xl border bg-background p-5"
            >
                <h2 class="flex items-center gap-2 font-medium">
                    <ListChecks class="size-4" /> How it’s graded
                </h2>
                <ul class="divide-y text-sm">
                    <li
                        v-for="(rule, i) in assignment.rules"
                        :key="i"
                        class="flex justify-between gap-3 py-2"
                    >
                        <span>{{ rule.title }}</span>
                        <span
                            class="shrink-0 text-muted-foreground tabular-nums"
                        >
                            {{ marks(rule.marks) }}
                        </span>
                    </li>
                    <li class="flex justify-between gap-3 py-2 font-medium">
                        <span>Total</span>
                        <span class="tabular-nums">
                            {{ marks(assignment.max_score) }}
                        </span>
                    </li>
                </ul>
            </section>

            <section
                v-if="submissions.length > 1"
                class="space-y-2 rounded-xl border bg-background p-5"
            >
                <h2 class="font-medium">Earlier submissions</h2>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="item in submissions.filter((s) => !s.is_current)"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-2 text-muted-foreground"
                    >
                        <a
                            :href="item.commit_url"
                            target="_blank"
                            rel="noopener"
                            class="font-mono text-xs hover:underline"
                        >
                            {{ item.short_sha }}
                        </a>
                        <span>{{ time(item.submitted_at) }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </StudentLayout>
</template>
