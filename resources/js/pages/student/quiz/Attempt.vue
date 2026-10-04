<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Check,
    CloudOff,
    Flag,
    LayoutGrid,
    Loader2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FullscreenGate from '@/components/student/FullscreenGate.vue';
import type { MapEntry } from '@/components/student/QuestionMap.vue';
import QuestionMap from '@/components/student/QuestionMap.vue';
import type {
    AnswerValue,
    StudentQuestion,
} from '@/components/student/QuizQuestion.vue';
import QuizQuestion from '@/components/student/QuizQuestion.vue';
import QuizTimer from '@/components/student/QuizTimer.vue';
import Watermark from '@/components/student/Watermark.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAttemptEvents } from '@/composables/useAttemptEvents';
import { useAutosave } from '@/composables/useAutosave';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { cn } from '@/lib/utils';
import { question as questionRoute, review, submit } from '@/routes/student';

type AttemptInfo = {
    public_id: string;
    deadline_at: string;
    server_now: string;
    total: number;
    furthest_position: number;
    one_way_navigation: boolean;
    require_fullscreen: boolean;
    track_focus: boolean;
};

type Entry = MapEntry & { saved_at: string | null };

const props = defineProps<{
    attempt: AttemptInfo;
    map: Entry[];
    position: number;
    question: StudentQuestion;
    answer: AnswerValue & { flagged: boolean; saved_at: string | null };
}>();

const page = usePage();
const context = computed(
    () => page.props.studentContext as { name: string; roll_number: string },
);

const autosave = useAutosave(props.attempt.public_id);
const status = autosave.status;
const events = useAttemptEvents(
    props.attempt.public_id,
    props.attempt.track_focus,
);

function onPaste(length: number) {
    if (props.attempt.track_focus && length > 0) {
        events.record({ type: 'pasted', position: props.position, length });
    }
}

/** Server clock minus this device's clock, to compare local edit times with server save times. */
const offset = computed(
    () => new Date(props.attempt.server_now).getTime() - Date.now(),
);

/** A local unsent copy only wins if it was edited after the server's last save. */
function isNewer(savedAt: string | null, editedAt: number): boolean {
    return !savedAt || editedAt + offset.value > new Date(savedAt).getTime();
}

const value = ref<AnswerValue>(copy(props.answer));
const flagged = ref(props.answer.flagged);
const mapOpen = ref(false);
const navigating = ref(false);
const confirmNextOpen = ref(false);

function copy(answer: AnswerValue): AnswerValue {
    return {
        selected_option_ids: [...answer.selected_option_ids],
        text_answer: answer.text_answer,
        code_answer: answer.code_answer,
    };
}

/** The last saved/loaded state; only real edits trigger a save. */
let baseline = '';
const snapshot = () => JSON.stringify([value.value, flagged.value]);

/** Reset local state whenever a new question arrives. */
watch(
    () => props.question.key,
    (position) => {
        const unsent = autosave.unsent(position);

        if (unsent && isNewer(props.answer.saved_at, unsent.editedAt)) {
            value.value = copy(unsent.payload);
            flagged.value = unsent.payload.flagged;
        } else {
            autosave.discard(position);
            value.value = copy(props.answer);
            flagged.value = props.answer.flagged;
        }

        baseline = snapshot();
    },
    { immediate: true },
);

function queueSave(immediate = false) {
    baseline = snapshot();
    autosave.queue(
        props.question.key,
        { ...value.value, flagged: flagged.value },
        immediate,
    );
}

watch(
    value,
    () => {
        if (snapshot() !== baseline) {
            queueSave();
        }
    },
    { deep: true },
);

function toggleFlag() {
    flagged.value = !flagged.value;
    queueSave(true);
}

const isLast = computed(() => props.position === props.attempt.total);
const oneWay = computed(() => props.attempt.one_way_navigation);

/** The map reflects unsaved changes to the current question too. */
const entries = computed<MapEntry[]>(() =>
    props.map.map((entry) =>
        entry.position === props.position
            ? {
                  ...entry,
                  flagged: flagged.value,
                  answered:
                      value.value.selected_option_ids.length > 0 ||
                      value.value.text_answer.trim() !== '' ||
                      value.value.code_answer.trim() !== '',
              }
            : entry,
    ),
);

const canVisit = (position: number) =>
    !oneWay.value ||
    position === props.position ||
    position === props.position + 1;

async function go(target: number | 'review') {
    if (navigating.value || target === props.position) {
        return;
    }

    if (oneWay.value && target !== 'review' && target !== props.position + 1) {
        return;
    }

    navigating.value = true;
    mapOpen.value = false;
    confirmNextOpen.value = false;

    // Never leave a question while its answer is unsaved (it may be closed in one-way mode).
    if (!(await autosave.flush())) {
        navigating.value = false;
        toast.error(
            "Your answer hasn't saved yet. Check your connection and try again.",
        );

        return;
    }

    router.visit(
        target === 'review'
            ? review.url(props.attempt.public_id)
            : questionRoute.url([props.attempt.public_id, target]),
        {
            replace: oneWay.value,
            onFinish: () => (navigating.value = false),
        },
    );
}

function next() {
    if (oneWay.value) {
        confirmNextOpen.value = true;
    } else {
        void go(props.position + 1);
    }
}

async function onExpired() {
    await autosave.flush();
    router.post(submit.url(props.attempt.public_id), { auto: true });
}

function onKeydown(event: KeyboardEvent) {
    const target = event.target as HTMLElement | null;

    // Arrow keys could skip a question for good in one-way mode, so they're off there.
    if (
        oneWay.value ||
        event.altKey ||
        event.ctrlKey ||
        event.metaKey ||
        target?.closest('input, textarea, [contenteditable="true"]')
    ) {
        return;
    }

    if (event.key === 'ArrowRight' && !isLast.value) {
        void go(props.position + 1);
    } else if (event.key === 'ArrowLeft' && props.position > 1) {
        void go(props.position - 1);
    }
}

/** Back/forward restores a page from history; reload it so the timer and answer are current. */
const onPopState = () => router.reload();

onMounted(() => {
    autosave.restoreUnsent((position, editedAt) =>
        isNewer(
            props.map.find((entry) => entry.position === position)?.saved_at ??
                null,
            editedAt,
        ),
    );
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('popstate', onPopState);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('popstate', onPopState);
});
</script>

<template>
    <Head :title="`Question ${position} of ${attempt.total}`" />

    <StudentLayout wide>
        <template #header>
            <QuizTimer
                :deadline-at="attempt.deadline_at"
                :server-now="attempt.server_now"
                @expired="onExpired"
            />
        </template>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_240px]">
            <section class="min-w-0 space-y-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-muted-foreground">
                        Question {{ position }} of {{ attempt.total }}
                    </p>
                    <div class="flex items-center gap-3">
                        <span
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                            aria-live="polite"
                        >
                            <template v-if="status === 'saving'">
                                <Loader2 class="size-3 animate-spin" /> Saving…
                            </template>
                            <template v-else-if="status === 'offline'">
                                <CloudOff class="size-3 text-amber-600" />
                                Offline, retrying
                            </template>
                            <template v-else-if="status === 'error'">
                                <AlertTriangle
                                    class="size-3 text-destructive"
                                />
                                Couldn't save
                            </template>
                            <template v-else-if="status === 'saved'">
                                <Check class="size-3 text-emerald-600" /> Saved
                            </template>
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            class="lg:hidden"
                            @click="mapOpen = true"
                        >
                            <LayoutGrid /> Questions
                        </Button>
                    </div>
                </div>

                <div
                    class="h-1 overflow-hidden rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-all"
                        :style="{
                            width: `${(position / attempt.total) * 100}%`,
                        }"
                    />
                </div>

                <div class="rounded-xl border bg-background p-5 sm:p-6">
                    <QuizQuestion
                        :key="question.key"
                        v-model="value"
                        :question="question"
                        @paste="onPaste"
                    />
                </div>

                <p v-if="oneWay" class="text-xs text-muted-foreground">
                    You can't come back to this question once you move on.
                </p>

                <div class="flex items-center justify-between gap-2">
                    <Button
                        v-if="!oneWay"
                        variant="outline"
                        :disabled="position === 1 || navigating"
                        @click="go(position - 1)"
                    >
                        <ArrowLeft /> Previous
                    </Button>
                    <span v-else />

                    <Button
                        v-if="!oneWay"
                        variant="ghost"
                        :class="
                            cn(
                                flagged &&
                                    'text-amber-700 hover:text-amber-700 dark:text-amber-400',
                            )
                        "
                        :aria-pressed="flagged"
                        @click="toggleFlag"
                    >
                        <Flag :class="flagged && 'fill-amber-400'" />
                        <span class="hidden sm:inline">
                            {{ flagged ? 'Flagged' : 'Flag for review' }}
                        </span>
                    </Button>

                    <Button v-if="!isLast" :disabled="navigating" @click="next">
                        Next <ArrowRight />
                    </Button>
                    <Button v-else :disabled="navigating" @click="go('review')">
                        Review & submit <ArrowRight />
                    </Button>
                </div>
            </section>

            <aside class="hidden lg:block">
                <div
                    class="sticky top-20 space-y-4 rounded-xl border bg-background p-4"
                >
                    <p class="text-sm font-medium">Questions</p>
                    <QuestionMap
                        :entries="entries"
                        :current="position"
                        :is-disabled="
                            (p) => !canVisit(p) || (oneWay && p !== position)
                        "
                        @go="go"
                    />
                    <Button
                        variant="outline"
                        class="w-full"
                        :disabled="(oneWay && !isLast) || navigating"
                        @click="go('review')"
                    >
                        Review & submit
                    </Button>
                </div>
            </aside>
        </div>

        <Sheet v-model:open="mapOpen">
            <SheetContent side="bottom" class="rounded-t-xl">
                <SheetHeader>
                    <SheetTitle>Questions</SheetTitle>
                </SheetHeader>
                <div class="space-y-4 px-4 pb-6">
                    <QuestionMap
                        :entries="entries"
                        :current="position"
                        :is-disabled="
                            (p) => !canVisit(p) || (oneWay && p !== position)
                        "
                        @go="go"
                    />
                    <Button
                        variant="outline"
                        class="w-full"
                        :disabled="(oneWay && !isLast) || navigating"
                        @click="go('review')"
                    >
                        Review & submit
                    </Button>
                </div>
            </SheetContent>
        </Sheet>

        <ConfirmDialog
            v-model:open="confirmNextOpen"
            title="Move to the next question?"
            description="You won't be able to come back to this question."
            confirm-label="Next question"
            :processing="navigating"
            @confirm="go(position + 1)"
        />

        <Watermark :text="`${context.name} · ${context.roll_number}`" />
        <FullscreenGate
            v-if="attempt.require_fullscreen"
            @exited="events.record({ type: 'fullscreen_exited' })"
        />
    </StudentLayout>
</template>
