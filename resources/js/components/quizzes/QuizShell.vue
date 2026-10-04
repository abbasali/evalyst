<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    CheckCircle2,
    CircleX,
    Lock,
    MoreHorizontal,
    Rocket,
    Trash2,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import QuizStateBadge from '@/components/quizzes/QuizStateBadge.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    access,
    archive,
    destroy,
    edit,
    publish,
    unarchive,
    unpublish,
} from '@/routes/quizzes';
import { index as questionsIndex } from '@/routes/quizzes/questions';
import type { PublishCheck, QuizSummary } from '@/types';

const props = defineProps<{
    quiz: QuizSummary;
    checklist: PublishCheck[];
    tab: 'settings' | 'questions' | 'access';
}>();

const { slug } = useCourse();
const args = computed(() => [slug.value, props.quiz.id] as [string, number]);

const tabs = computed(() => [
    { key: 'settings', label: 'Settings', href: edit(args.value) },
    {
        key: 'questions',
        label: 'Questions',
        href: questionsIndex(args.value),
        count: props.quiz.questions_count,
    },
    {
        key: 'access',
        label: 'Access',
        href: access(args.value),
        count: props.quiz.participants_count,
    },
]);

const readyCount = computed(
    () => props.checklist.filter((check) => check.ok).length,
);
const ready = computed(
    () =>
        props.checklist.length > 0 &&
        readyCount.value === props.checklist.length,
);

const publishOpen = ref(false);
const deleteOpen = ref(false);
const processing = ref(false);

function post(url: string, onSuccess?: () => void) {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess,
        },
    );
}

function remove() {
    router.visit(destroy(args.value), {
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <div class="flex flex-1 flex-col gap-5 p-4 md:p-6">
        <div
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0 space-y-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ quiz.title }}
                    </h1>
                    <QuizStateBadge :state="quiz.state" />
                </div>
                <p
                    class="flex flex-wrap gap-x-2 gap-y-1 text-sm text-muted-foreground"
                >
                    <span v-if="quiz.opens_at">
                        Opens {{ formatInCourseTz(quiz.opens_at) }}
                    </span>
                    <span v-if="quiz.opens_at" aria-hidden="true">·</span>
                    <span>Closes {{ formatInCourseTz(quiz.closes_at) }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ quiz.duration_minutes ?? '—' }} min</span>
                    <span aria-hidden="true">·</span>
                    <span>
                        {{ quiz.questions_count }}
                        {{
                            quiz.questions_count === 1
                                ? 'question'
                                : 'questions'
                        }}, {{ quiz.max_score }} marks
                    </span>
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <Button v-if="quiz.can.publish" @click="publishOpen = true">
                    <Rocket /> Publish
                    <span
                        v-if="!ready"
                        class="rounded-full bg-primary-foreground/20 px-1.5 text-xs"
                    >
                        {{ readyCount }}/{{ checklist.length }}
                    </span>
                </Button>
                <Button
                    v-if="quiz.can.unarchive"
                    variant="outline"
                    :disabled="processing"
                    @click="post(unarchive.url(args))"
                >
                    <ArchiveRestore /> Unarchive
                </Button>
                <DropdownMenu
                    v-if="
                        quiz.can.unpublish ||
                        quiz.can.archive ||
                        quiz.can.delete
                    "
                >
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="More quiz actions"
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            v-if="quiz.can.unpublish"
                            @click="post(unpublish.url(args))"
                        >
                            <Undo2 /> Move back to draft
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="quiz.can.archive"
                            @click="post(archive.url(args))"
                        >
                            <Archive /> Archive
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="quiz.can.delete"
                            variant="destructive"
                            @click="deleteOpen = true"
                        >
                            <Trash2 /> Delete quiz
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <Alert v-if="quiz.state === 'archived'">
            <Archive class="size-4" />
            <AlertTitle>This quiz is archived</AlertTitle>
            <AlertDescription>
                It's read-only and hidden from the main tabs. Results stay
                available. Unarchive it to make changes.
            </AlertDescription>
        </Alert>
        <Alert v-else-if="quiz.has_attempts">
            <Lock class="size-4" />
            <AlertTitle>Students have started</AlertTitle>
            <AlertDescription>
                Questions, marks, duration, and the access, shuffle and
                anti-cheating settings are locked so every student is scored the
                same way. You can still edit the title and instructions, extend
                the closing time, change how results are released, and add
                students.
            </AlertDescription>
        </Alert>

        <nav
            class="-mb-px flex gap-1 overflow-x-auto border-b"
            aria-label="Quiz"
        >
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="item.href"
                preserve-scroll
                :class="
                    cn(
                        'flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                        tab === item.key
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )
                "
                :aria-current="tab === item.key ? 'page' : undefined"
            >
                {{ item.label }}
                <span
                    v-if="item.count !== undefined"
                    class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground"
                >
                    {{ item.count }}
                </span>
            </Link>
            <span
                v-for="later in ['Monitor', 'Results']"
                :key="later"
                class="flex cursor-default items-center gap-1.5 border-b-2 border-transparent px-3 py-2 text-sm font-medium whitespace-nowrap text-muted-foreground/60"
                title="Coming soon"
            >
                {{ later }}
                <span class="rounded-full border px-1.5 text-[10px]">soon</span>
            </span>
        </nav>

        <slot />

        <Dialog v-model:open="publishOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Publish “{{ quiz.title }}”?</DialogTitle>
                    <DialogDescription>
                        Once published, students can join
                        {{
                            quiz.opens_at
                                ? `from ${formatInCourseTz(quiz.opens_at)}`
                                : 'straight away'
                        }}
                        until {{ formatInCourseTz(quiz.closes_at) }}.
                    </DialogDescription>
                </DialogHeader>
                <ul class="space-y-2 text-sm">
                    <li
                        v-for="check in checklist"
                        :key="check.key"
                        class="flex items-start gap-2"
                    >
                        <CheckCircle2
                            v-if="check.ok"
                            class="mt-0.5 size-4 shrink-0 text-emerald-600"
                        />
                        <CircleX
                            v-else
                            class="mt-0.5 size-4 shrink-0 text-destructive"
                        />
                        <span :class="!check.ok && 'font-medium'">
                            {{ check.label }}
                        </span>
                    </li>
                </ul>
                <DialogFooter class="gap-2">
                    <Button variant="secondary" @click="publishOpen = false">
                        Cancel
                    </Button>
                    <Button
                        :disabled="!ready || processing"
                        @click="
                            post(publish.url(args), () => (publishOpen = false))
                        "
                    >
                        <Spinner v-if="processing" />
                        Publish now
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this quiz?"
            description="The draft and its question list will be removed. Questions stay in the bank."
            confirm-label="Delete"
            destructive
            :processing="processing"
            @confirm="remove"
        />
    </div>
</template>
