<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CircleDot,
    Code2,
    ListChecks,
    Minus,
    Plus,
    Sparkles,
    TextCursorInput,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import TagSelect from '@/components/questions/TagSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { show, store } from '@/routes/question-generations';
import { index as questionsIndex } from '@/routes/questions';
import type { Option, QuestionType, TagSummary, Team } from '@/types';

type HistoryRow = {
    id: number;
    prompt: string;
    requested: number;
    generated: number;
    accepted: number;
    status: 'pending' | 'running' | 'completed' | 'failed';
    cost_usd: number;
    user: string | null;
    created_at: string;
};

const props = defineProps<{
    tags: TagSummary[];
    difficulties: Option[];
    maxQuestions: number;
    history: HistoryRow[];
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: 'Question bank',
                href: questionsIndex(props.currentTeam.slug),
            },
            { title: 'Generate with AI', href: '#' },
        ],
    }),
});

const { slug } = useCourse();

const form = useForm({
    prompt: '',
    type_counts: {
        single_choice: 5,
        multiple_choice: 3,
        open_text: 1,
        open_code: 1,
    } as Record<QuestionType, number>,
    difficulty: 'mixed',
    include_code_output: true,
    tag_ids: [] as number[],
});

const counters: {
    type: QuestionType;
    label: string;
    icon: typeof CircleDot;
}[] = [
    { type: 'single_choice', label: 'Single choice', icon: CircleDot },
    { type: 'multiple_choice', label: 'Multiple choice', icon: ListChecks },
    { type: 'open_text', label: 'Open text', icon: TextCursorInput },
    { type: 'open_code', label: 'Text + code', icon: Code2 },
];

const total = computed(() =>
    Object.values(form.type_counts).reduce(
        (sum, n) => sum + (Number(n) || 0),
        0,
    ),
);

function step(type: QuestionType, delta: number) {
    form.type_counts[type] = Math.min(
        15,
        Math.max(0, (Number(form.type_counts[type]) || 0) + delta),
    );
}

const statusVariant = {
    pending: 'secondary',
    running: 'info',
    completed: 'success',
    failed: 'destructive',
} as const;

const examples = [
    'Python variables, data types and control flow for beginners',
    'JavaScript promises, async/await and the event loop',
    'SQL joins, GROUP BY and aggregate functions',
];
</script>

<template>
    <Head title="Generate questions with AI" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <div class="space-y-1">
            <h1
                class="flex items-center gap-2 text-xl font-semibold tracking-tight"
            >
                <Sparkles class="size-5" /> Generate questions with AI
            </h1>
            <p class="text-sm text-muted-foreground">
                Describe a topic and choose how many questions of each type you
                want. You'll review every draft before anything is added to the
                bank.
            </p>
        </div>

        <form
            class="grid gap-6 rounded-xl border p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_300px]"
            @submit.prevent="form.submit(store(slug))"
        >
            <div class="space-y-6">
                <div class="space-y-2">
                    <Label for="prompt">Topic</Label>
                    <Textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="4"
                        maxlength="1000"
                        placeholder="e.g. Java collections for beginners; focus on ArrayList, HashMap and iterating over them"
                        :aria-invalid="!!form.errors.prompt || undefined"
                        v-focus
                    />
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-muted-foreground">Try:</span>
                        <button
                            v-for="example in examples"
                            :key="example"
                            type="button"
                            class="rounded-full border px-2.5 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            @click="form.prompt = example"
                        >
                            {{ example }}
                        </button>
                    </div>
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label>Questions</Label>
                        <Badge
                            :variant="
                                total > maxQuestions || total === 0
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{ total }} / {{ maxQuestions }}
                        </Badge>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="counter in counters"
                            :key="counter.type"
                            class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
                        >
                            <span class="flex items-center gap-2 text-sm">
                                <component
                                    :is="counter.icon"
                                    class="size-4 text-muted-foreground"
                                />
                                {{ counter.label }}
                            </span>
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-7"
                                    :aria-label="`Fewer ${counter.label}`"
                                    :disabled="
                                        form.type_counts[counter.type] <= 0
                                    "
                                    @click="step(counter.type, -1)"
                                >
                                    <Minus />
                                </Button>
                                <input
                                    v-model.number="
                                        form.type_counts[counter.type]
                                    "
                                    type="number"
                                    min="0"
                                    max="15"
                                    :aria-label="`${counter.label} count`"
                                    class="w-10 [appearance:textfield] bg-transparent text-center text-sm font-medium tabular-nums outline-none [&::-webkit-inner-spin-button]:appearance-none"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-7"
                                    :aria-label="`More ${counter.label}`"
                                    :disabled="
                                        form.type_counts[counter.type] >= 15
                                    "
                                    @click="step(counter.type, 1)"
                                >
                                    <Plus />
                                </Button>
                            </div>
                        </div>
                    </div>
                    <InputError :message="form.errors.type_counts" />
                </div>
            </div>

            <div class="space-y-5">
                <div class="space-y-2">
                    <Label for="difficulty">Difficulty</Label>
                    <NativeSelect id="difficulty" v-model="form.difficulty">
                        <option value="mixed">Mixed</option>
                        <option
                            v-for="d in difficulties"
                            :key="d.value"
                            :value="d.value"
                        >
                            {{ d.label }}
                        </option>
                    </NativeSelect>
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <Checkbox
                        v-model="form.include_code_output"
                        class="mt-0.5"
                    />
                    <span>
                        Include “what does this code output?” questions
                        <span class="block text-xs text-muted-foreground">
                            Answer keys are double-checked by a second AI pass.
                        </span>
                    </span>
                </label>

                <div class="space-y-2">
                    <Label>Tags for accepted questions</Label>
                    <TagSelect v-model="form.tag_ids" :tags="tags" />
                    <p class="text-xs text-muted-foreground">
                        Questions with these tags are also used to avoid
                        duplicates.
                    </p>
                    <InputError :message="form.errors.tag_ids" />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="
                        form.processing || total === 0 || total > maxQuestions
                    "
                >
                    <Spinner v-if="form.processing" />
                    <Sparkles v-else />
                    Generate {{ total }}
                    {{ total === 1 ? 'question' : 'questions' }}
                </Button>
            </div>
        </form>

        <section v-if="history.length" class="space-y-3">
            <h2 class="text-base font-medium">Recent generations</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Topic</th>
                            <th class="px-4 py-2.5 font-medium">Status</th>
                            <th class="px-4 py-2.5 text-right font-medium">
                                Added
                            </th>
                            <th
                                class="hidden px-4 py-2.5 text-right font-medium md:table-cell"
                            >
                                Cost
                            </th>
                            <th
                                class="hidden px-4 py-2.5 font-medium md:table-cell"
                            >
                                When
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in history"
                            :key="row.id"
                            class="hover:bg-muted/30"
                        >
                            <td class="max-w-md px-4 py-2.5">
                                <Link
                                    :href="show([slug, row.id])"
                                    class="hover:underline"
                                >
                                    {{ row.prompt }}
                                </Link>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.user ?? 'Someone' }} ·
                                    {{ row.requested }} requested
                                </p>
                            </td>
                            <td class="px-4 py-2.5">
                                <Badge
                                    :variant="statusVariant[row.status]"
                                    class="capitalize"
                                >
                                    {{ row.status }}
                                </Badge>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">
                                {{ row.accepted }} / {{ row.generated }}
                            </td>
                            <td
                                class="hidden px-4 py-2.5 text-right text-muted-foreground tabular-nums md:table-cell"
                            >
                                ${{ row.cost_usd.toFixed(4) }}
                            </td>
                            <td
                                class="hidden px-4 py-2.5 text-muted-foreground md:table-cell"
                            >
                                {{ formatInCourseTz(row.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
