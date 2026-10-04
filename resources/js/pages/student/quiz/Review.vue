<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Flag, Send } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { MapEntry } from '@/components/student/QuestionMap.vue';
import QuestionMap from '@/components/student/QuestionMap.vue';
import QuizTimer from '@/components/student/QuizTimer.vue';
import { Button } from '@/components/ui/button';
import { useAutosave } from '@/composables/useAutosave';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { question, submit } from '@/routes/student';

const props = defineProps<{
    attempt: {
        public_id: string;
        deadline_at: string;
        server_now: string;
        total: number;
        one_way_navigation: boolean;
    };
    map: (MapEntry & { saved_at: string | null })[];
}>();

const autosave = useAutosave(props.attempt.public_id);
const offset = new Date(props.attempt.server_now).getTime() - Date.now();

/** Send anything typed but not yet saved (e.g. offline earlier) before submitting. */
onMounted(() => {
    autosave.restoreUnsent((position, editedAt) => {
        const savedAt = props.map.find(
            (entry) => entry.position === position,
        )?.saved_at;

        return !savedAt || editedAt + offset > new Date(savedAt).getTime();
    });
    window.addEventListener('popstate', onPopState);
});

const onPopState = () => router.reload();
onBeforeUnmount(() => window.removeEventListener('popstate', onPopState));

const unanswered = computed(() =>
    props.map.filter((entry) => !entry.answered).map((entry) => entry.position),
);
const flagged = computed(() =>
    props.map.filter((entry) => entry.flagged).map((entry) => entry.position),
);

const confirmOpen = ref(false);
const processing = ref(false);

function goTo(position: number) {
    router.visit(question.url([props.attempt.public_id, position]));
}

async function send(auto = false) {
    processing.value = true;

    if (!(await autosave.flush()) && !auto) {
        processing.value = false;
        toast.error(
            "Some answers haven't saved yet. Check your connection and try again.",
        );

        return;
    }

    router.post(
        submit.url(props.attempt.public_id),
        { auto },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Head title="Review & submit" />

    <StudentLayout>
        <template #header>
            <QuizTimer
                :deadline-at="attempt.deadline_at"
                :server-now="attempt.server_now"
                @expired="send(true)"
            />
        </template>

        <div class="space-y-6">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    Review & submit
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ attempt.total - unanswered.length }} of
                    {{ attempt.total }} questions answered.
                    <template v-if="!attempt.one_way_navigation">
                        Click a number to go back to it.
                    </template>
                </p>
            </div>

            <div class="rounded-xl border bg-background p-5">
                <QuestionMap
                    :entries="map"
                    :is-disabled="() => attempt.one_way_navigation"
                    @go="goTo"
                />
            </div>

            <div
                v-if="unanswered.length"
                class="flex gap-3 rounded-xl border border-amber-500/40 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>
                    <span class="font-medium">
                        {{ unanswered.length }} not answered:
                    </span>
                    question {{ unanswered.join(', ') }}.
                </p>
            </div>
            <div
                v-if="flagged.length"
                class="flex gap-3 rounded-xl border p-4 text-sm"
            >
                <Flag
                    class="mt-0.5 size-4 shrink-0 fill-amber-400 text-amber-500"
                />
                <p>
                    <span class="font-medium">Flagged for review:</span>
                    question {{ flagged.join(', ') }}.
                </p>
            </div>

            <div
                class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between"
            >
                <Button
                    v-if="!attempt.one_way_navigation"
                    variant="outline"
                    size="lg"
                    @click="goTo(attempt.total)"
                >
                    <ArrowLeft /> Back to questions
                </Button>
                <span v-else />
                <Button size="lg" @click="confirmOpen = true">
                    <Send /> Submit quiz
                </Button>
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Submit your quiz?"
            :description="
                unanswered.length
                    ? `You haven't answered ${unanswered.length} ${unanswered.length === 1 ? 'question' : 'questions'}. You can't change anything after submitting.`
                    : 'You can\'t change your answers after submitting.'
            "
            confirm-label="Submit"
            :processing="processing"
            @confirm="send()"
        />
    </StudentLayout>
</template>
