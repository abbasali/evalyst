<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { KeyRound, Lock, Users } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import MarkdownEditor from '@/components/markdown/MarkdownEditor.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { cn } from '@/lib/utils';
import { index, store, update } from '@/routes/quizzes';
import type {
    Option,
    PublishCheck,
    QuizSettingsForm,
    QuizSummary,
    Team,
} from '@/types';

const props = defineProps<{
    form: QuizSettingsForm | null;
    quiz?: QuizSummary;
    checklist?: PublishCheck[];
    lockedFields?: string[];
    accessModeLocked?: boolean;
    releaseModes: Option[];
    accessModes: Option[];
    defaultThreshold: number;
}>();

defineOptions({
    layout: (props: { currentTeam: Team; quiz?: QuizSummary }) => ({
        breadcrumbs: [
            { title: 'Quizzes', href: index(props.currentTeam.slug) },
            { title: props.quiz?.title ?? 'New quiz', href: '#' },
        ],
    }),
});

const { slug, course } = useCourse();

const form = useForm<QuizSettingsForm>(
    props.form
        ? { ...props.form }
        : {
              title: '',
              instructions: '',
              opens_at: '',
              closes_at: '',
              duration_minutes: 30,
              shuffle_questions: false,
              shuffle_options: false,
              show_answers_after_release: true,
              track_focus: true,
              one_way_navigation: false,
              require_fullscreen: false,
              release_mode: 'manual',
              auto_publish_threshold: '',
              access_mode: 'roster',
          },
);

const readOnly = computed(() => !!props.quiz && !props.quiz.can.update);
const isLocked = (field: string) =>
    readOnly.value || (props.lockedFields ?? []).includes(field);
const accessLocked = computed(
    () => isLocked('access_mode') || !!props.accessModeLocked,
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function submit() {
    form.transform((data) => ({
        ...data,
        auto_publish_threshold:
            data.auto_publish_threshold === ''
                ? null
                : data.auto_publish_threshold,
    }));

    if (props.quiz) {
        form.submit(update([slug.value, props.quiz.id]), {
            preserveScroll: true,
            onSuccess: () => form.defaults(),
        });
    } else {
        form.submit(store(slug.value));
    }
}

const accessChoices = [
    {
        value: 'roster' as const,
        icon: Users,
        title: 'Roster codes',
        hint: 'Pick students from your roster. Each gets a personal 6-character code.',
    },
    {
        value: 'shared_code' as const,
        icon: KeyRound,
        title: 'Shared code',
        hint: 'One 6-character code for the class. Students type their name and roll number.',
    },
];

const releaseChoices = [
    {
        value: 'manual' as const,
        title: 'Manual',
        hint: 'You release results when you are ready.',
    },
    {
        value: 'automatic' as const,
        title: 'Automatic',
        hint: 'Released once the quiz closes and every attempt is in.',
    },
];
</script>

<template>
    <Head :title="quiz ? `${quiz.title} · Settings` : 'New quiz'" />

    <component
        :is="quiz ? QuizShell : 'div'"
        v-bind="
            quiz
                ? { quiz, checklist: checklist ?? [], tab: 'settings' }
                : { class: 'flex flex-1 flex-col gap-6 p-4 md:p-6' }
        "
    >
        <div v-if="!quiz" class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">New quiz</h1>
            <p class="text-sm text-muted-foreground">
                Set the basics now. You'll pick questions and students next, and
                nothing is visible to students until you publish.
            </p>
        </div>

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="font-medium">Basics</h2>
                <div class="space-y-2">
                    <Label for="title">Title</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        v-focus="!quiz"
                        maxlength="150"
                        placeholder="Week 3 — Loops and functions"
                        :disabled="readOnly"
                    />
                    <InputError :message="errors.title" />
                </div>
                <div class="space-y-2">
                    <Label for="instructions">
                        Instructions
                        <span class="font-normal text-muted-foreground">
                            (optional, shown before students start)
                        </span>
                    </Label>
                    <MarkdownEditor
                        id="instructions"
                        v-model="form.instructions"
                        :rows="4"
                        :readonly="readOnly"
                        placeholder="Closed book. You can go back to earlier questions until you submit."
                    />
                    <InputError :message="errors.instructions" />
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-5">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="font-medium">Schedule</h2>
                    <span class="text-xs text-muted-foreground">
                        Times are in the course timezone ({{ course.timezone }})
                    </span>
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="space-y-2">
                        <Label for="opens_at">Opens at</Label>
                        <Input
                            id="opens_at"
                            v-model="form.opens_at"
                            type="datetime-local"
                            :disabled="isLocked('opens_at')"
                        />
                        <p class="text-xs text-muted-foreground">
                            Empty = opens when you publish.
                        </p>
                        <InputError :message="errors.opens_at" />
                    </div>
                    <div class="space-y-2">
                        <Label for="closes_at">Closes at</Label>
                        <Input
                            id="closes_at"
                            v-model="form.closes_at"
                            type="datetime-local"
                            required
                            :disabled="readOnly"
                        />
                        <p class="text-xs text-muted-foreground">
                            The last moment to start.
                            <template v-if="lockedFields?.length">
                                Can only be extended now.
                            </template>
                        </p>
                        <InputError :message="errors.closes_at" />
                    </div>
                    <div class="space-y-2">
                        <Label for="duration_minutes">Duration (minutes)</Label>
                        <Input
                            id="duration_minutes"
                            v-model="form.duration_minutes"
                            type="number"
                            min="1"
                            max="600"
                            required
                            :disabled="isLocked('duration_minutes')"
                        />
                        <p class="text-xs text-muted-foreground">
                            Students who start just before closing still get the
                            full time.
                        </p>
                        <InputError :message="errors.duration_minutes" />
                    </div>
                </div>
            </section>

            <section class="space-y-3 rounded-xl border p-5">
                <div class="space-y-1">
                    <h2 class="font-medium">During the quiz</h2>
                    <p class="text-xs text-muted-foreground">
                        Every quiz also watermarks the screen with the student's
                        name and roll number, and blocks copying question text.
                    </p>
                </div>
                <label
                    v-for="toggle in [
                        {
                            field: 'shuffle_questions',
                            label: 'Shuffle question order',
                            hint: 'Each student gets the questions in a different order.',
                        },
                        {
                            field: 'shuffle_options',
                            label: 'Shuffle answer options',
                            hint: 'Choice options appear in a different order for each student.',
                        },
                        {
                            field: 'track_focus',
                            label: 'Track focus loss and pasting',
                            hint: 'Records when a student leaves the quiz tab or pastes into an answer. Students are told about it.',
                        },
                        {
                            field: 'one_way_navigation',
                            label: 'One-way navigation',
                            hint: 'Students can\'t go back to earlier questions or flag them for later.',
                        },
                        {
                            field: 'require_fullscreen',
                            label: 'Require fullscreen',
                            hint: 'The quiz is hidden until the browser is fullscreen. Leaving fullscreen is recorded. (Not available on iPhone.)',
                        },
                    ] as const"
                    :key="toggle.field"
                    :class="
                        cn(
                            'flex items-start gap-3',
                            isLocked(toggle.field) && 'opacity-60',
                        )
                    "
                >
                    <Checkbox
                        v-model="form[toggle.field]"
                        class="mt-0.5"
                        :disabled="isLocked(toggle.field)"
                    />
                    <span class="space-y-0.5">
                        <span
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            {{ toggle.label }}
                            <Lock
                                v-if="isLocked(toggle.field) && !readOnly"
                                class="size-3 text-muted-foreground"
                            />
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ toggle.hint }}
                        </span>
                    </span>
                </label>
            </section>

            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="font-medium">Results</h2>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="choice in releaseChoices"
                        :key="choice.value"
                        type="button"
                        :disabled="readOnly"
                        :class="
                            cn(
                                'rounded-lg border p-3 text-left transition-colors disabled:cursor-not-allowed',
                                form.release_mode === choice.value
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'hover:bg-muted/50',
                            )
                        "
                        @click="form.release_mode = choice.value"
                    >
                        <span class="block text-sm font-medium">
                            {{ choice.title }} release
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ choice.hint }}
                        </span>
                    </button>
                </div>
                <label class="flex items-start gap-3">
                    <Checkbox
                        v-model="form.show_answers_after_release"
                        class="mt-0.5"
                        :disabled="readOnly"
                    />
                    <span class="space-y-0.5">
                        <span class="block text-sm font-medium">
                            Show correct answers and explanations
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Students see them with their results, once released.
                        </span>
                    </span>
                </label>
                <div class="space-y-2">
                    <Label for="auto_publish_threshold">
                        AI auto-publish confidence
                        <span class="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <Input
                        id="auto_publish_threshold"
                        v-model="form.auto_publish_threshold"
                        type="number"
                        min="0.5"
                        max="1"
                        step="0.05"
                        class="max-w-32"
                        :placeholder="defaultThreshold.toFixed(2)"
                        :disabled="readOnly"
                    />
                    <p class="text-xs text-muted-foreground">
                        AI grades for open answers are published automatically
                        only when the AI is at least this confident (0.50–1.00).
                        Anything less goes to your review inbox. Leave empty for
                        the default ({{ defaultThreshold.toFixed(2) }}).
                    </p>
                    <InputError :message="errors.auto_publish_threshold" />
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-5">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="font-medium">How students get in</h2>
                    <span
                        v-if="accessLocked && !readOnly"
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Lock class="size-3" />
                        Locked once students are added or the quiz is published
                    </span>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="choice in accessChoices"
                        :key="choice.value"
                        type="button"
                        :disabled="accessLocked"
                        :class="
                            cn(
                                'flex items-start gap-3 rounded-lg border p-3 text-left transition-colors disabled:cursor-not-allowed',
                                form.access_mode === choice.value
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'hover:bg-muted/50',
                                accessLocked &&
                                    form.access_mode !== choice.value &&
                                    'opacity-40',
                            )
                        "
                        @click="form.access_mode = choice.value"
                    >
                        <component
                            :is="choice.icon"
                            class="mt-0.5 size-4 shrink-0"
                        />
                        <span>
                            <span class="block text-sm font-medium">
                                {{ choice.title }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ choice.hint }}
                            </span>
                        </span>
                    </button>
                </div>
                <InputError :message="errors.access_mode" />
            </section>

            <div v-if="!readOnly" class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ quiz ? 'Save settings' : 'Create quiz' }}
                </Button>
                <Button v-if="!quiz" variant="ghost" as-child>
                    <Link :href="index(slug)">Cancel</Link>
                </Button>
                <span
                    v-if="quiz && form.isDirty"
                    class="text-xs text-muted-foreground"
                >
                    Unsaved changes
                </span>
            </div>
        </form>
    </component>
</template>
