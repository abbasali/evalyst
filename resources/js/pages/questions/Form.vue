<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CircleDot,
    Code2,
    Copy,
    ListChecks,
    Lock,
    TextCursorInput,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import MarkdownEditor from '@/components/markdown/MarkdownEditor.vue';
import OptionsEditor from '@/components/questions/OptionsEditor.vue';
import TagSelect from '@/components/questions/TagSelect.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { cn } from '@/lib/utils';
import { duplicate, index, store, update } from '@/routes/questions';
import type {
    Question,
    QuestionFormOptions,
    QuestionOption,
    QuestionType,
    Team,
} from '@/types';

const props = defineProps<
    QuestionFormOptions & {
        question: Question | null;
    }
>();

defineOptions({
    layout: (props: { currentTeam: Team; question: Question | null }) => ({
        breadcrumbs: [
            { title: 'Question bank', href: index(props.currentTeam.slug) },
            {
                title: props.question ? 'Edit question' : 'New question',
                href: '#',
            },
        ],
    }),
});

const { slug } = useCourse();
const locked = computed(() => props.question?.locked ?? false);

const blankOptions = (): QuestionOption[] =>
    Array.from({ length: 4 }, () => ({ body: '', is_correct: false }));

const form = useForm({
    type: (props.question?.type ?? 'single_choice') as QuestionType,
    body: props.question?.body ?? '',
    options: props.question?.options.length
        ? props.question.options.map((option) => ({ ...option }))
        : blankOptions(),
    code_language: props.question?.code_language ?? 'php',
    default_marks: props.question?.default_marks ?? 1,
    scoring_policy: props.question?.scoring_policy ?? 'all_or_nothing',
    model_answer: props.question?.model_answer ?? '',
    rubric: props.question?.rubric ?? '',
    explanation: props.question?.explanation ?? '',
    difficulty: props.question?.difficulty ?? '',
    tag_ids: props.question?.tags.map((tag) => tag.id) ?? [],
    needs_verification: props.question?.needs_verification ?? false,
    add_another: false,
});

const isChoice = computed(
    () => form.type === 'single_choice' || form.type === 'multiple_choice',
);

// Switching to single choice keeps only the first correct option.
watch(
    () => form.type,
    (type) => {
        if (type !== 'single_choice') {
            return;
        }

        const first = form.options.findIndex((option) => option.is_correct);
        form.options = form.options.map((option, i) => ({
            ...option,
            is_correct: i === first,
        }));
    },
);

const typeChoices: {
    value: QuestionType;
    label: string;
    hint: string;
    icon: typeof CircleDot;
}[] = [
    {
        value: 'single_choice',
        label: 'Single choice',
        hint: 'One right answer',
        icon: CircleDot,
    },
    {
        value: 'multiple_choice',
        label: 'Multiple choice',
        hint: 'Several right answers',
        icon: ListChecks,
    },
    {
        value: 'open_text',
        label: 'Open text',
        hint: 'Written answer, AI-graded',
        icon: TextCursorInput,
    },
    {
        value: 'open_code',
        label: 'Text + code',
        hint: 'Explanation and code, AI-graded',
        icon: Code2,
    },
];

const policyHelp: Record<string, string> = {
    all_or_nothing:
        'Full marks only if exactly the correct options are selected.',
    partial:
        'Marks × (correct picks ÷ correct options); zero if any wrong option is picked.',
    partial_with_penalty:
        'Marks × (correct picks − wrong picks) ÷ correct options, never below zero.',
};

function submit(addAnother = false) {
    form.add_another = addAnother;
    form.transform((data) => ({
        ...data,
        difficulty: data.difficulty || null,
        options: isChoice.value ? data.options : [],
    }));

    const options = {
        preserveScroll: !addAnother,
        onSuccess: () => {
            if (addAnother) {
                form.reset();
                window.scrollTo({ top: 0 });
                document.getElementById('body')?.focus();
            }
        },
    };

    if (props.question) {
        form.submit(update([slug.value, props.question.id]), options);
    } else {
        form.submit(store(slug.value), options);
    }
}

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);
const tagError = computed(
    () =>
        errors.value.tag_ids ??
        Object.entries(errors.value).find(([key]) =>
            key.startsWith('tag_ids.'),
        )?.[1],
);
</script>

<template>
    <Head :title="question ? 'Edit question' : 'New question'" />

    <form
        class="flex flex-1 flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit()"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ question ? 'Edit question' : 'New question' }}
            </h1>
        </div>

        <Alert v-if="locked">
            <Lock class="size-4" />
            <AlertTitle>Students have answered this question</AlertTitle>
            <AlertDescription class="flex flex-wrap items-center gap-3">
                The wording, options and type are locked so grading stays fair.
                You can still change marks, the model answer, rubric and
                explanation.
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="router.post(duplicate.url([slug, question!.id]))"
                >
                    <Copy /> Duplicate to edit wording
                </Button>
            </AlertDescription>
        </Alert>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-6">
                <section class="space-y-2">
                    <Label>Question type</Label>
                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        <button
                            v-for="choice in typeChoices"
                            :key="choice.value"
                            type="button"
                            :disabled="locked"
                            :class="
                                cn(
                                    'flex items-start gap-3 rounded-lg border p-3 text-left transition-colors disabled:cursor-not-allowed',
                                    form.type === choice.value
                                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                        : 'hover:bg-muted/50',
                                    locked &&
                                        form.type !== choice.value &&
                                        'opacity-40',
                                )
                            "
                            @click="form.type = choice.value"
                        >
                            <component
                                :is="choice.icon"
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <span>
                                <span class="block text-sm font-medium">
                                    {{ choice.label }}
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ choice.hint }}
                                </span>
                            </span>
                        </button>
                    </div>
                    <InputError :message="errors.type" />
                </section>

                <section class="space-y-2">
                    <Label for="body">Question</Label>
                    <MarkdownEditor
                        id="body"
                        v-model="form.body"
                        :rows="6"
                        :readonly="locked"
                        :invalid="!!errors.body"
                        placeholder="What does the following code print?&#10;&#10;```php&#10;echo 10 <=> 5;&#10;```"
                    />
                    <InputError :message="errors.body" />
                </section>

                <section v-if="isChoice" class="space-y-2">
                    <Label>Options</Label>
                    <OptionsEditor
                        v-model="form.options"
                        :multiple="form.type === 'multiple_choice'"
                        :disabled="locked"
                        :errors="errors"
                    />
                </section>

                <template v-else>
                    <section v-if="form.type === 'open_code'" class="space-y-2">
                        <Label for="code_language">Code language</Label>
                        <NativeSelect
                            id="code_language"
                            v-model="form.code_language"
                            :disabled="locked"
                            class="max-w-xs"
                        >
                            <option
                                v-for="language in codeLanguages"
                                :key="language.value"
                                :value="language.value"
                            >
                                {{ language.label }}
                            </option>
                        </NativeSelect>
                        <p class="text-xs text-muted-foreground">
                            Students get a text box and a
                            {{ form.code_language }}
                            code editor.
                        </p>
                        <InputError :message="errors.code_language" />
                    </section>

                    <section class="space-y-2">
                        <Label for="model_answer">Model answer</Label>
                        <MarkdownEditor
                            id="model_answer"
                            v-model="form.model_answer"
                            :rows="5"
                            :invalid="!!errors.model_answer"
                            placeholder="What a complete, correct answer looks like. The AI grades against this."
                        />
                        <InputError :message="errors.model_answer" />
                    </section>

                    <section class="space-y-2">
                        <Label for="rubric">
                            Rubric
                            <span class="font-normal text-muted-foreground">
                                (recommended)
                            </span>
                        </Label>
                        <MarkdownEditor
                            id="rubric"
                            v-model="form.rubric"
                            :rows="4"
                            placeholder="- Explains what a closure is (1 mark)&#10;- Uses `use` to capture a variable (1 mark)"
                        />
                        <InputError :message="errors.rubric" />
                    </section>
                </template>

                <section class="space-y-2">
                    <Label for="explanation">
                        Explanation
                        <span class="font-normal text-muted-foreground">
                            (shown to students after results are released)
                        </span>
                    </Label>
                    <MarkdownEditor
                        id="explanation"
                        v-model="form.explanation"
                        :rows="3"
                    />
                    <InputError :message="errors.explanation" />
                </section>
            </div>

            <aside class="space-y-5 lg:sticky lg:top-4 lg:self-start">
                <div class="space-y-5 rounded-xl border p-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-2">
                            <Label for="default_marks">Marks</Label>
                            <Input
                                id="default_marks"
                                v-model.number="form.default_marks"
                                type="number"
                                min="0.5"
                                max="100"
                                step="0.5"
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="difficulty">Difficulty</Label>
                            <NativeSelect
                                id="difficulty"
                                v-model="form.difficulty"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="difficulty in difficulties"
                                    :key="difficulty.value"
                                    :value="difficulty.value"
                                >
                                    {{ difficulty.label }}
                                </option>
                            </NativeSelect>
                        </div>
                    </div>
                    <InputError :message="errors.default_marks" />
                    <InputError :message="errors.difficulty" />

                    <div
                        v-if="form.type === 'multiple_choice'"
                        class="space-y-2"
                    >
                        <Label for="scoring_policy">Scoring</Label>
                        <NativeSelect
                            id="scoring_policy"
                            v-model="form.scoring_policy"
                        >
                            <option
                                v-for="policy in scoringPolicies"
                                :key="policy.value"
                                :value="policy.value"
                            >
                                {{ policy.label }}
                            </option>
                        </NativeSelect>
                        <p class="text-xs text-muted-foreground">
                            {{ policyHelp[form.scoring_policy] }}
                        </p>
                        <InputError :message="errors.scoring_policy" />
                    </div>

                    <div class="space-y-2">
                        <Label>Tags</Label>
                        <TagSelect v-model="form.tag_ids" :tags="tags" />
                        <InputError :message="tagError" />
                    </div>

                    <label
                        v-if="question?.needs_verification"
                        class="flex items-start gap-2 rounded-lg bg-amber-50 p-3 text-sm dark:bg-amber-950/40"
                    >
                        <Checkbox
                            :model-value="!form.needs_verification"
                            class="mt-0.5"
                            @update:model-value="
                                (value) => (form.needs_verification = !value)
                            "
                        />
                        <span>
                            I've checked the answer key
                            <span class="block text-xs text-muted-foreground">
                                The AI verifier disagreed with it when the
                                question was generated.
                            </span>
                        </span>
                    </label>
                </div>
            </aside>
        </div>

        <div
            class="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t bg-background/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6"
        >
            <Button variant="ghost" as-child>
                <Link :href="index(slug)">Cancel</Link>
            </Button>
            <Button
                v-if="!question"
                type="button"
                variant="outline"
                :disabled="form.processing"
                @click="submit(true)"
            >
                Save & add another
            </Button>
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                Save question
            </Button>
        </div>
    </form>
</template>
