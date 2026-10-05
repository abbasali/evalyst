<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { KeyRound, Lock, Users } from '@lucide/vue';
import { computed } from 'vue';
import AssignmentShell from '@/components/assignments/AssignmentShell.vue';
import InputError from '@/components/InputError.vue';
import MarkdownEditor from '@/components/markdown/MarkdownEditor.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCourse } from '@/composables/useCourse';
import { cn } from '@/lib/utils';
import { index, store, update } from '@/routes/assignments';
import type { AssignmentSummary, Option, PublishCheck, Team } from '@/types';

type SettingsForm = {
    title: string;
    instructions: string;
    opens_at: string;
    closes_at: string;
    late_policy: 'not_allowed' | 'allowed' | 'penalty';
    penalty_type: 'fixed' | 'per_hour' | 'per_day';
    penalty_value: number | '';
    penalty_cap: number | '';
    grace_minutes: number;
    hard_cutoff_at: string;
    allow_resubmission: boolean;
    show_rules_to_students: boolean;
    extra_ignored_paths: string;
    release_results: boolean;
    release_mode: 'manual' | 'automatic';
    auto_publish_threshold: number | '';
    access_mode: 'roster' | 'shared_code';
};

const props = defineProps<{
    form: SettingsForm | null;
    assignment?: AssignmentSummary;
    checklist?: PublishCheck[];
    accessModeLocked?: boolean;
    releaseModes: Option[];
    accessModes: Option[];
    latePolicies: Option[];
    penaltyTypes: Option[];
    defaultThreshold: number;
}>();

defineOptions({
    layout: (props: { currentTeam: Team; assignment?: AssignmentSummary }) => ({
        breadcrumbs: [
            { title: 'Assignments', href: index(props.currentTeam.slug) },
            {
                title: props.assignment?.title ?? 'New assignment',
                href: '#',
            },
        ],
    }),
});

const { slug, course } = useCourse();

const form = useForm<SettingsForm>(
    props.form
        ? { ...props.form }
        : {
              title: '',
              instructions: '',
              opens_at: '',
              closes_at: '',
              late_policy: 'penalty',
              penalty_type: 'per_day',
              penalty_value: 1,
              penalty_cap: '',
              grace_minutes: 0,
              hard_cutoff_at: '',
              allow_resubmission: true,
              show_rules_to_students: true,
              extra_ignored_paths: '',
              release_results: true,
              release_mode: 'manual',
              auto_publish_threshold: '',
              access_mode: 'roster',
          },
);

const readOnly = computed(
    () => !!props.assignment && !props.assignment.can.update,
);
const accessLocked = computed(() => readOnly.value || !!props.accessModeLocked);
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

/** What students will read, live. */
const latePreview = computed(() => {
    const cutoff = form.hard_cutoff_at
        ? ` No submissions after ${new Date(form.hard_cutoff_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })}.`
        : '';
    const grace =
        Number(form.grace_minutes) > 0
            ? ` There is a ${form.grace_minutes}-minute grace period.`
            : '';

    if (form.late_policy === 'not_allowed') {
        return `Late submissions are not accepted.${grace}`;
    }

    if (form.late_policy === 'allowed') {
        return `Late submissions are accepted without a penalty.${cutoff}${grace}`;
    }

    const value = form.penalty_value || '?';
    const unit =
        form.penalty_type === 'per_hour'
            ? ' for each started hour'
            : form.penalty_type === 'per_day'
              ? ' for each started day'
              : '';
    const cap = form.penalty_cap ? `, up to ${form.penalty_cap} marks` : '';

    return `Late submissions lose ${value} marks${unit}${cap}.${cutoff}${grace}`;
});

function submit() {
    form.transform((data) => ({
        ...data,
        auto_publish_threshold:
            data.auto_publish_threshold === ''
                ? null
                : data.auto_publish_threshold,
    }));

    if (props.assignment) {
        form.submit(update([slug.value, props.assignment.id]), {
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
        hint: 'Released once the deadline has passed.',
    },
];
</script>

<template>
    <Head
        :title="
            assignment ? `${assignment.title} · Settings` : 'New assignment'
        "
    />

    <component
        :is="assignment ? AssignmentShell : 'div'"
        v-bind="
            assignment
                ? { assignment, checklist: checklist ?? [], tab: 'settings' }
                : { class: 'flex flex-1 flex-col gap-6 p-4 md:p-6' }
        "
    >
        <div v-if="!assignment" class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">New assignment</h1>
            <p class="text-sm text-muted-foreground">
                Describe the project and the deadline. You'll add grading rules
                and students next. Nothing is visible to students until you
                publish.
            </p>
        </div>

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="font-medium">The project</h2>
                <div class="space-y-2">
                    <Label for="title">Title</Label>
                    <Input
                        id="title"
                        v-model="form.title"
                        v-focus="!assignment"
                        maxlength="150"
                        placeholder="Blog with posts, comments and tests"
                        :disabled="readOnly"
                    />
                    <InputError :message="errors.title" />
                </div>
                <div class="space-y-2">
                    <Label for="instructions">Problem statement</Label>
                    <MarkdownEditor
                        id="instructions"
                        v-model="form.instructions"
                        :rows="10"
                        :readonly="readOnly"
                        placeholder="Build a web app where… Requirements: …"
                    />
                    <p class="text-xs text-muted-foreground">
                        Students read this, and the AI grades against it.
                    </p>
                    <InputError :message="errors.instructions" />
                </div>
            </section>

            <section class="space-y-4 rounded-xl border p-5">
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <h2 class="font-medium">Deadline &amp; late work</h2>
                    <span class="text-xs text-muted-foreground">
                        Times are in the course timezone ({{ course.timezone }})
                    </span>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="opens_at">Opens at</Label>
                        <Input
                            id="opens_at"
                            v-model="form.opens_at"
                            type="datetime-local"
                            :disabled="readOnly"
                        />
                        <p class="text-xs text-muted-foreground">
                            Empty = opens when you publish.
                        </p>
                        <InputError :message="errors.opens_at" />
                    </div>
                    <div class="space-y-2">
                        <Label for="closes_at">Deadline</Label>
                        <Input
                            id="closes_at"
                            v-model="form.closes_at"
                            type="datetime-local"
                            required
                            :disabled="readOnly"
                        />
                        <InputError :message="errors.closes_at" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="late_policy">Late submissions</Label>
                        <NativeSelect
                            id="late_policy"
                            v-model="form.late_policy"
                            :disabled="readOnly"
                        >
                            <option
                                v-for="policy in latePolicies"
                                :key="policy.value"
                                :value="policy.value"
                            >
                                {{ policy.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="space-y-2">
                        <Label for="grace_minutes"
                            >Grace period (minutes)</Label
                        >
                        <Input
                            id="grace_minutes"
                            v-model="form.grace_minutes"
                            type="number"
                            min="0"
                            max="1440"
                            :disabled="readOnly"
                        />
                        <InputError :message="errors.grace_minutes" />
                    </div>
                </div>

                <div
                    v-if="form.late_policy === 'penalty'"
                    class="grid gap-4 sm:grid-cols-3"
                >
                    <div class="space-y-2">
                        <Label for="penalty_type">Penalty</Label>
                        <NativeSelect
                            id="penalty_type"
                            v-model="form.penalty_type"
                            :disabled="readOnly"
                        >
                            <option
                                v-for="type in penaltyTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="errors.penalty_type" />
                    </div>
                    <div class="space-y-2">
                        <Label for="penalty_value">Marks deducted</Label>
                        <Input
                            id="penalty_value"
                            v-model="form.penalty_value"
                            type="number"
                            min="0.5"
                            step="0.5"
                            :disabled="readOnly"
                        />
                        <InputError :message="errors.penalty_value" />
                    </div>
                    <div class="space-y-2">
                        <Label for="penalty_cap">
                            Cap
                            <span class="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="penalty_cap"
                            v-model="form.penalty_cap"
                            type="number"
                            min="0.5"
                            step="0.5"
                            :disabled="readOnly"
                        />
                        <InputError :message="errors.penalty_cap" />
                    </div>
                </div>

                <div
                    v-if="form.late_policy !== 'not_allowed'"
                    class="space-y-2"
                >
                    <Label for="hard_cutoff_at">
                        Hard cutoff
                        <span class="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </Label>
                    <Input
                        id="hard_cutoff_at"
                        v-model="form.hard_cutoff_at"
                        type="datetime-local"
                        class="max-w-xs"
                        :disabled="readOnly"
                    />
                    <InputError :message="errors.hard_cutoff_at" />
                </div>

                <p class="rounded-lg bg-muted/50 px-3 py-2 text-sm">
                    <span class="text-muted-foreground">Students see:</span>
                    {{ latePreview }}
                </p>
                <p class="text-xs text-muted-foreground">
                    You can give any student their own deadline or waive their
                    penalty later, from the Submissions tab.
                </p>

                <label class="flex items-start gap-3">
                    <Checkbox
                        v-model="form.allow_resubmission"
                        class="mt-0.5"
                        :disabled="readOnly"
                    />
                    <span class="space-y-0.5">
                        <span class="block text-sm font-medium">
                            Allow resubmission before the deadline
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Only the latest submission is graded.
                        </span>
                    </span>
                </label>
            </section>

            <section class="space-y-4 rounded-xl border p-5">
                <h2 class="font-medium">Grading &amp; results</h2>
                <label class="flex items-start gap-3">
                    <Checkbox
                        v-model="form.show_rules_to_students"
                        class="mt-0.5"
                        :disabled="readOnly"
                    />
                    <span class="space-y-0.5">
                        <span class="block text-sm font-medium">
                            Show the grading rules to students
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Titles and marks only, not how checks are
                            configured.
                        </span>
                    </span>
                </label>
                <label class="flex items-start gap-3">
                    <Checkbox
                        v-model="form.release_results"
                        class="mt-0.5"
                        :disabled="readOnly"
                    />
                    <span class="space-y-0.5">
                        <span class="block text-sm font-medium">
                            Release results to students
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            When this is off, students never see scores or
                            feedback. After submitting, they're told their
                            instructor will grade it.
                        </span>
                    </span>
                </label>
                <p
                    v-if="
                        props.form &&
                        !props.form.release_results &&
                        form.release_results &&
                        form.release_mode === 'automatic'
                    "
                    class="text-xs text-amber-700 dark:text-amber-400"
                >
                    With automatic release, students see their results as soon
                    as you save if the deadline has passed.
                </p>
                <template v-if="form.release_results">
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
                </template>
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
                        A submission is published automatically only when the AI
                        is at least this confident on every AI rule. Leave empty
                        for the default ({{ defaultThreshold.toFixed(2) }}).
                    </p>
                    <InputError :message="errors.auto_publish_threshold" />
                </div>
                <div class="space-y-2">
                    <Label for="extra_ignored_paths">
                        Extra paths to ignore
                        <span class="font-normal text-muted-foreground">
                            (optional, one glob per line)
                        </span>
                    </Label>
                    <Textarea
                        id="extra_ignored_paths"
                        v-model="form.extra_ignored_paths"
                        rows="3"
                        class="font-mono text-xs"
                        placeholder="public/vendor/**&#10;docs/**"
                        :disabled="readOnly"
                    />
                    <p class="text-xs text-muted-foreground">
                        vendor, node_modules, build output, lock files and
                        binaries are always skipped.
                    </p>
                    <InputError :message="errors.extra_ignored_paths" />
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
                        Locked once students are added or it is published
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
                    {{ assignment ? 'Save settings' : 'Create assignment' }}
                </Button>
                <Button v-if="!assignment" variant="ghost" as-child>
                    <Link :href="index(slug)">Cancel</Link>
                </Button>
                <span
                    v-if="assignment && form.isDirty"
                    class="text-xs text-muted-foreground"
                >
                    Unsaved changes
                </span>
            </div>
        </form>
    </component>
</template>
