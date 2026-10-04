<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CalendarClock,
    CheckCircle2,
    Clock,
    Eye,
    ListOrdered,
    Lock,
    Maximize,
    Play,
    ShieldAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import Markdown from '@/components/markdown/Markdown.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { formatInCourseTz } from '@/lib/datetime';
import { done, join, resume, start } from '@/routes/student';

const props = defineProps<{
    quiz: {
        public_id: string;
        title: string;
        instructions: string | null;
        duration_minutes: number;
        questions_count: number;
        max_score: number;
        opens_at: string | null;
        closes_at: string;
        timezone: string;
        track_focus: boolean;
        one_way_navigation: boolean;
        require_fullscreen: boolean;
    };
    state:
        | 'not_started'
        | 'upcoming'
        | 'closed'
        | 'in_progress'
        | 'blocked'
        | 'submitted';
    attempt: {
        public_id: string;
        deadline_at: string;
        position: number;
    } | null;
}>();

const processing = ref(false);
const time = (iso: string | null) =>
    formatInCourseTz(iso, undefined, props.quiz.timezone);

/** Fullscreen needs a click, so request it here before starting. */
async function enterFullscreen() {
    if (props.quiz.require_fullscreen && document.fullscreenEnabled) {
        try {
            await document.documentElement.requestFullscreen();
        } catch {
            // The quiz page asks again.
        }
    }
}

async function begin(action: 'start' | 'resume') {
    processing.value = true;
    await enterFullscreen();
    router.post(
        (action === 'start' ? start : resume).url(props.quiz.public_id),
        {},
        { onFinish: () => (processing.value = false) },
    );
}
</script>

<template>
    <Head :title="quiz.title" />

    <StudentLayout>
        <div class="space-y-6">
            <div class="space-y-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ quiz.title }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Closes {{ time(quiz.closes_at) }}
                </p>
            </div>

            <dl class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border bg-background p-4">
                    <dt
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Clock class="size-3.5" /> Time
                    </dt>
                    <dd class="mt-1 text-lg font-semibold">
                        {{ quiz.duration_minutes }} min
                    </dd>
                </div>
                <div class="rounded-xl border bg-background p-4">
                    <dt
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <ListOrdered class="size-3.5" /> Questions
                    </dt>
                    <dd class="mt-1 text-lg font-semibold">
                        {{ quiz.questions_count }}
                    </dd>
                </div>
                <div class="rounded-xl border bg-background p-4">
                    <dt
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <CheckCircle2 class="size-3.5" /> Marks
                    </dt>
                    <dd class="mt-1 text-lg font-semibold">
                        {{ quiz.max_score }}
                    </dd>
                </div>
            </dl>

            <div
                v-if="quiz.instructions"
                class="rounded-xl border bg-background p-5"
            >
                <h2 class="mb-2 text-sm font-medium">Instructions</h2>
                <Markdown :source="quiz.instructions" />
            </div>

            <ul
                v-if="state === 'not_started' || state === 'in_progress'"
                class="space-y-2 rounded-xl border bg-background p-5 text-sm"
            >
                <li class="flex gap-2">
                    <Clock
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    The timer starts when you press Start and keeps running if
                    you close the page. When it reaches zero your answers are
                    submitted automatically.
                </li>
                <li v-if="quiz.one_way_navigation" class="flex gap-2">
                    <ArrowLeftRight
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    You can't go back to a question once you move to the next
                    one.
                </li>
                <li v-if="quiz.require_fullscreen" class="flex gap-2">
                    <Maximize
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    The quiz runs in fullscreen. Leaving fullscreen is recorded.
                </li>
                <li v-if="quiz.track_focus" class="flex gap-2">
                    <Eye class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    Leaving this tab and pasting into answers are recorded.
                </li>
            </ul>

            <Alert v-if="state === 'upcoming'">
                <CalendarClock class="size-4" />
                <AlertTitle>Not open yet</AlertTitle>
                <AlertDescription>
                    This quiz opens {{ time(quiz.opens_at) }}. Come back then.
                </AlertDescription>
            </Alert>
            <Alert v-else-if="state === 'closed'">
                <Lock class="size-4" />
                <AlertTitle>This quiz has closed</AlertTitle>
                <AlertDescription>
                    It's no longer possible to start it.
                </AlertDescription>
            </Alert>
            <Alert v-else-if="state === 'blocked'" variant="destructive">
                <ShieldAlert class="size-4" />
                <AlertTitle>Already in progress on another device</AlertTitle>
                <AlertDescription>
                    This roll number already has a quiz in progress in a
                    different browser. Go back to that browser, or ask your
                    instructor to allow you to resume here.
                </AlertDescription>
            </Alert>

            <div class="flex flex-col gap-2 sm:flex-row">
                <Button
                    v-if="state === 'not_started'"
                    size="lg"
                    :disabled="processing"
                    @click="begin('start')"
                >
                    <Spinner v-if="processing" /><Play v-else /> Start quiz
                </Button>
                <Button
                    v-else-if="state === 'in_progress'"
                    size="lg"
                    :disabled="processing"
                    @click="begin('resume')"
                >
                    <Spinner v-if="processing" /><Play v-else /> Resume quiz
                </Button>
                <Button
                    v-else-if="state === 'blocked'"
                    size="lg"
                    variant="outline"
                    @click="router.reload()"
                >
                    Check again
                </Button>
                <Button
                    v-else-if="state === 'submitted' && attempt"
                    size="lg"
                    as-child
                >
                    <Link :href="done(attempt.public_id)">
                        <CheckCircle2 /> View submission
                    </Link>
                </Button>
                <Button variant="ghost" size="lg" as-child>
                    <Link :href="join()">Use a different code</Link>
                </Button>
            </div>
        </div>
    </StudentLayout>
</template>
