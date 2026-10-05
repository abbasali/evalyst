<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Eye, Flag, LayoutGrid, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCourse } from '@/composables/useCourse';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { index as questionsIndex } from '@/routes/quizzes/questions';

/**
 * Instructor preview of the student quiz screen. Answers live in memory only.
 */
const props = defineProps<{
    quiz: {
        id: number;
        title: string;
        course: string;
        duration_minutes: number;
        one_way_navigation: boolean;
        require_fullscreen: boolean;
        release_results: boolean;
    };
    questions: StudentQuestion[];
    serverNow: string;
}>();

const { slug } = useCourse();
const backUrl = computed(() => questionsIndex.url([slug.value, props.quiz.id]));

const deadline = new Date(
    new Date(props.serverNow).getTime() + props.quiz.duration_minutes * 60_000,
).toISOString();

const position = ref(1);
const answers = reactive<Record<number, AnswerValue>>(
    Object.fromEntries(
        props.questions.map((question) => [
            question.key,
            { selected_option_ids: [], text_answer: '', code_answer: '' },
        ]),
    ),
);
const flags = reactive<Record<number, boolean>>({});
const mapOpen = ref(false);
const finished = ref(false);

const current = computed(() => props.questions[position.value - 1]);
const isLast = computed(() => position.value === props.questions.length);

const entries = computed<MapEntry[]>(() =>
    props.questions.map((question, index) => {
        const answer = answers[question.key];

        return {
            position: index + 1,
            flagged: !!flags[question.key],
            answered:
                answer.selected_option_ids.length > 0 ||
                answer.text_answer.trim() !== '' ||
                answer.code_answer.trim() !== '',
        };
    }),
);

const canVisit = (target: number) =>
    !props.quiz.one_way_navigation ||
    target === position.value ||
    target === position.value + 1;

function go(target: number) {
    if (target >= 1 && target <= props.questions.length && canVisit(target)) {
        position.value = target;
        mapOpen.value = false;
        window.scrollTo({ top: 0 });
    }
}
</script>

<template>
    <Head :title="`Preview · ${quiz.title}`" />

    <StudentLayout wide :title="quiz.title" :course="quiz.course">
        <template #header>
            <QuizTimer
                v-if="questions.length && quiz.duration_minutes > 0"
                :deadline-at="deadline"
                :server-now="serverNow"
                @expired="finished = true"
            />
            <Button variant="ghost" size="sm" as-child>
                <Link :href="backUrl"><X /> Close preview</Link>
            </Button>
        </template>

        <div
            class="mb-5 flex items-start gap-2 rounded-lg border border-sky-500/40 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:bg-sky-950/40 dark:text-sky-200"
        >
            <Eye class="mt-0.5 size-4 shrink-0" />
            <p>
                <span class="font-medium">Instructor preview.</span>
                Nothing is saved and questions aren't locked.
                <template v-if="quiz.require_fullscreen">
                    Students must also go fullscreen.
                </template>
            </p>
        </div>

        <p
            v-if="questions.length === 0"
            class="rounded-xl border bg-background p-10 text-center text-sm text-muted-foreground"
        >
            This quiz has no questions yet.
        </p>

        <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_240px]">
            <section class="min-w-0 space-y-5">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-muted-foreground">
                        Question {{ position }} of {{ questions.length }}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        class="lg:hidden"
                        @click="mapOpen = true"
                    >
                        <LayoutGrid /> Questions
                    </Button>
                </div>

                <div class="rounded-xl border bg-background p-5 sm:p-6">
                    <QuizQuestion
                        :key="current.key"
                        v-model="answers[current.key]"
                        :question="current"
                    />
                </div>

                <div class="flex items-center justify-between gap-2">
                    <Button
                        v-if="!quiz.one_way_navigation"
                        variant="outline"
                        :disabled="position === 1"
                        @click="go(position - 1)"
                    >
                        <ArrowLeft /> Previous
                    </Button>
                    <span v-else />
                    <Button
                        v-if="!quiz.one_way_navigation"
                        variant="ghost"
                        @click="flags[current.key] = !flags[current.key]"
                    >
                        <Flag :class="flags[current.key] && 'fill-amber-400'" />
                        <span class="hidden sm:inline">Flag for review</span>
                    </Button>
                    <Button v-if="!isLast" @click="go(position + 1)">
                        Next <ArrowRight />
                    </Button>
                    <Button v-else @click="finished = true">
                        Submit <ArrowRight />
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
                        :is-disabled="(p) => !canVisit(p)"
                        @go="go"
                    />
                </div>
            </aside>
        </div>

        <Sheet v-model:open="mapOpen">
            <SheetContent side="bottom" class="rounded-t-xl">
                <SheetHeader>
                    <SheetTitle>Questions</SheetTitle>
                </SheetHeader>
                <div class="px-4 pb-6">
                    <QuestionMap
                        :entries="entries"
                        :current="position"
                        :is-disabled="(p) => !canVisit(p)"
                        @go="go"
                    />
                </div>
            </SheetContent>
        </Sheet>

        <Dialog v-model:open="finished">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Preview — nothing saved</DialogTitle>
                    <DialogDescription>
                        {{
                            quiz.release_results
                                ? 'Students would now see a confirmation page with their results link.'
                                : 'Students would now see a confirmation page saying their instructor will grade it.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="secondary" @click="finished = false">
                        Keep previewing
                    </Button>
                    <Button as-child>
                        <Link :href="backUrl">Back to the quiz</Link>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Watermark text="Preview · Student name · Roll number" />
    </StudentLayout>
</template>
