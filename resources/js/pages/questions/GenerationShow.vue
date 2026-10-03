<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Pencil,
    RotateCcw,
    Sparkles,
    Terminal,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Markdown from '@/components/markdown/Markdown.vue';
import DraftEditDialog from '@/components/questions/DraftEditDialog.vue';
import QuestionTypeBadge from '@/components/questions/QuestionTypeBadge.vue';
import QuestionView from '@/components/questions/QuestionView.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { accept, create, retry } from '@/routes/question-generations';
import { index as questionsIndex } from '@/routes/questions';
import type { GeneratedDraft, Option, Team } from '@/types';

type Generation = {
    id: number;
    prompt: string;
    status: 'pending' | 'running' | 'completed' | 'failed';
    stale: boolean;
    requested: number;
    difficulty: string;
    warnings: string[];
    error: string | null;
    accepted_count: number;
    cost_usd: number;
    tags: string[];
};

const props = defineProps<{
    generation: Generation;
    drafts: GeneratedDraft[];
    scoringPolicies: Option[];
    codeLanguages: Option[];
    difficulties: Option[];
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            {
                title: 'Question bank',
                href: questionsIndex(props.currentTeam.slug),
            },
            { title: 'Generate with AI', href: create(props.currentTeam.slug) },
            { title: 'Drafts', href: '#' },
        ],
    }),
});

const { slug } = useCourse();

// Poll while the job is working.
const working = computed(() =>
    ['pending', 'running'].includes(props.generation.status),
);
const { stop, start } = usePoll(
    3000,
    { only: ['generation', 'drafts'] },
    { autoStart: working.value },
);
watch(working, (value) => (value ? start() : stop()));

// Local copies so instructor edits survive reloads; accepted flags come from the server.
const drafts = ref<GeneratedDraft[]>([]);
const selected = ref<string[]>([]);

watch(
    () => props.drafts,
    (incoming) => {
        const local = new Map(drafts.value.map((d) => [d.uid, d]));
        const fresh = drafts.value.length === 0;

        drafts.value = incoming.map((draft) =>
            local.has(draft.uid) && !draft.accepted
                ? { ...local.get(draft.uid)!, accepted: false }
                : draft,
        );

        selected.value = fresh
            ? incoming
                  .filter(
                      (d) =>
                          !d.accepted && d.verification.status !== 'disputed',
                  )
                  .map((d) => d.uid)
            : selected.value.filter((uid) =>
                  incoming.some((d) => d.uid === uid && !d.accepted),
              );
    },
    { immediate: true },
);

const available = computed(() => drafts.value.filter((d) => !d.accepted));
const disputedCount = computed(
    () =>
        available.value.filter((d) => d.verification.status === 'disputed')
            .length,
);

function selectWhere(predicate: (draft: GeneratedDraft) => boolean) {
    selected.value = available.value.filter(predicate).map((d) => d.uid);
}

function toggle(uid: string, value: boolean | 'indeterminate') {
    selected.value =
        value === true
            ? [...selected.value, uid]
            : selected.value.filter((id) => id !== uid);
}

// Editing
const editing = ref<GeneratedDraft | null>(null);
const editOpen = ref(false);

function edit(draft: GeneratedDraft) {
    editing.value = draft;
    editOpen.value = true;
}

function saveEdit(updated: GeneratedDraft) {
    drafts.value = drafts.value.map((d) =>
        d.uid === updated.uid ? updated : d,
    );

    if (!selected.value.includes(updated.uid)) {
        selected.value = [...selected.value, updated.uid];
    }
}

// Accepting
const processing = ref(false);
const errors = ref<string[]>([]);

function addSelected() {
    const chosen = drafts.value.filter((d) => selected.value.includes(d.uid));
    const payload = chosen.map(
        ({ verification: _v, accepted: _a, is_code_output: _c, ...fields }) =>
            fields,
    );
    // Map "drafts.{i}.field" errors back to the card numbers shown on the page.
    const cardNumber = (i: number) =>
        drafts.value.findIndex((d) => d.uid === chosen[i]?.uid) + 1;

    router.post(
        accept.url([slug.value, props.generation.id]),
        { drafts: payload },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
                errors.value = [];
            },
            onFinish: () => (processing.value = false),
            onError: (bag) =>
                (errors.value = [
                    ...new Set(
                        Object.entries(bag).map(([key, message]) => {
                            const match = key.match(/^drafts\.(\d+)\./);

                            return match
                                ? `Draft #${cardNumber(Number(match[1]))}: ${message.replace(/drafts\.\d+\./g, '').replaceAll('_', ' ')}`
                                : message;
                        }),
                    ),
                ]),
        },
    );
}

const letter = (index: number) => String.fromCharCode(65 + index);

const retrying = ref(false);

function retryGeneration() {
    router.post(
        retry.url([slug.value, props.generation.id]),
        {},
        {
            onStart: () => (retrying.value = true),
            onFinish: () => (retrying.value = false),
        },
    );
}
</script>

<template>
    <Head title="Generated drafts" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="space-y-2">
            <h1
                class="flex items-center gap-2 text-xl font-semibold tracking-tight"
            >
                <Sparkles class="size-5" /> Generated drafts
            </h1>
            <p class="max-w-3xl text-sm">{{ generation.prompt }}</p>
            <div class="flex flex-wrap gap-1.5 text-xs">
                <Badge variant="outline"
                    >{{ generation.requested }} requested</Badge
                >
                <Badge variant="outline" class="capitalize">{{
                    generation.difficulty
                }}</Badge>
                <Badge
                    v-for="tag in generation.tags"
                    :key="tag"
                    variant="secondary"
                >
                    {{ tag }}
                </Badge>
                <Badge v-if="generation.cost_usd" variant="outline">
                    ${{ generation.cost_usd.toFixed(4) }}
                </Badge>
            </div>
        </div>

        <!-- Working -->
        <div v-if="working" class="space-y-4">
            <div
                class="flex items-center gap-3 rounded-xl border bg-muted/30 p-4 text-sm"
            >
                <Spinner class="size-5" />
                <div>
                    <p class="font-medium">
                        {{
                            generation.status === 'pending'
                                ? 'Queued…'
                                : 'Writing and checking questions…'
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        This usually takes 20–60 seconds. You can leave this
                        page and come back.
                    </p>
                </div>
                <Button
                    v-if="generation.stale"
                    size="sm"
                    variant="outline"
                    class="ml-auto"
                    :disabled="retrying"
                    @click="retryGeneration"
                >
                    <RotateCcw /> Taking too long? Retry
                </Button>
            </div>
            <div
                v-for="n in 3"
                :key="n"
                class="space-y-3 rounded-xl border p-5"
            >
                <Skeleton class="h-4 w-1/3" />
                <Skeleton class="h-4 w-full" />
                <Skeleton class="h-4 w-5/6" />
                <Skeleton class="h-9 w-full" />
            </div>
        </div>

        <!-- Failed -->
        <Alert v-else-if="generation.status === 'failed'" variant="destructive">
            <AlertTriangle class="size-4" />
            <AlertTitle>Generation failed</AlertTitle>
            <AlertDescription class="space-y-3">
                <p>{{ generation.error ?? 'Something went wrong.' }}</p>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="retrying"
                    @click="retryGeneration"
                >
                    <RotateCcw /> Try again
                </Button>
            </AlertDescription>
        </Alert>

        <!-- Completed -->
        <template v-else>
            <Alert v-if="generation.warnings.length">
                <AlertTriangle class="size-4" />
                <AlertTitle>{{ generation.warnings[0] }}</AlertTitle>
                <AlertDescription v-if="generation.warnings.length > 1">
                    <ul class="list-disc pl-4">
                        <li
                            v-for="warning in generation.warnings.slice(1)"
                            :key="warning"
                        >
                            {{ warning }}
                        </li>
                    </ul>
                </AlertDescription>
            </Alert>

            <div
                class="sticky top-0 z-10 -mx-4 flex flex-wrap items-center gap-2 border-b bg-background/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6"
            >
                <template v-if="available.length">
                    <span class="text-sm font-medium">
                        {{ selected.length }} of {{ available.length }} selected
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="selectWhere(() => true)"
                        >All</Button
                    >
                    <Button size="sm" variant="ghost" @click="selected = []"
                        >None</Button
                    >
                </template>
                <span v-else class="text-sm font-medium"
                    >All drafts have been added.</span
                >
                <Button
                    v-if="disputedCount"
                    size="sm"
                    variant="ghost"
                    @click="
                        selectWhere((d) => d.verification.status !== 'disputed')
                    "
                >
                    Only verified
                </Button>
                <div class="ml-auto flex items-center gap-2">
                    <Button
                        v-if="generation.accepted_count"
                        size="sm"
                        variant="outline"
                        as-child
                    >
                        <Link
                            :href="
                                questionsIndex(slug, {
                                    query: { generation: generation.id },
                                })
                            "
                        >
                            View {{ generation.accepted_count }} in bank
                        </Link>
                    </Button>
                    <Button
                        v-if="available.length"
                        size="sm"
                        :disabled="!selected.length || processing"
                        @click="addSelected"
                    >
                        <Spinner v-if="processing" />
                        Add {{ selected.length }} to bank
                    </Button>
                </div>
            </div>

            <Alert v-if="errors.length" variant="destructive">
                <AlertTitle>Some drafts need fixing first</AlertTitle>
                <AlertDescription>
                    <ul class="list-disc pl-4">
                        <li v-for="error in errors" :key="error">
                            {{ error }}
                        </li>
                    </ul>
                </AlertDescription>
            </Alert>

            <p
                v-if="!drafts.length"
                class="py-10 text-center text-sm text-muted-foreground"
            >
                No usable questions were generated. Try a more specific topic.
            </p>

            <article
                v-for="(draft, index) in drafts"
                :key="draft.uid"
                :class="[
                    'rounded-xl border transition-colors',
                    draft.accepted && 'opacity-60',
                    selected.includes(draft.uid) &&
                        'border-primary/60 ring-1 ring-primary/30',
                ]"
            >
                <header
                    class="flex flex-wrap items-center gap-2 border-b px-4 py-2.5"
                >
                    <Checkbox
                        v-if="!draft.accepted"
                        :model-value="selected.includes(draft.uid)"
                        :aria-label="`Select draft ${index + 1}`"
                        @update:model-value="(v) => toggle(draft.uid, v)"
                    />
                    <span class="text-xs text-muted-foreground"
                        >#{{ index + 1 }}</span
                    >
                    <QuestionTypeBadge :type="draft.type" />
                    <Badge variant="outline" class="font-normal">
                        {{ draft.default_marks }}
                        {{ draft.default_marks === 1 ? 'mark' : 'marks' }}
                    </Badge>
                    <Badge
                        v-if="draft.difficulty"
                        variant="outline"
                        class="font-normal capitalize"
                    >
                        {{ draft.difficulty }}
                    </Badge>
                    <Badge
                        v-if="draft.is_code_output"
                        variant="outline"
                        class="font-normal"
                    >
                        <Terminal /> Code output
                    </Badge>
                    <Badge
                        v-if="draft.accepted"
                        variant="success"
                        class="ml-auto"
                    >
                        <CheckCircle2 /> Added to bank
                    </Badge>
                    <template v-else>
                        <Badge
                            v-if="draft.verification.status === 'agreed'"
                            variant="success"
                            class="ml-auto"
                        >
                            <CheckCircle2 /> Answer verified
                        </Badge>
                        <Button
                            size="sm"
                            variant="ghost"
                            :class="
                                draft.verification.status !== 'agreed' &&
                                'ml-auto'
                            "
                            @click="edit(draft)"
                        >
                            <Pencil /> Edit
                        </Button>
                    </template>
                </header>

                <div class="space-y-4 p-4 md:p-5">
                    <div
                        v-if="
                            draft.verification.status === 'disputed' &&
                            !draft.accepted
                        "
                        class="flex gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/40"
                    >
                        <AlertTriangle
                            class="mt-0.5 size-4 shrink-0 text-amber-600"
                        />
                        <div class="space-y-1">
                            <p class="font-medium">
                                Answer key disputed — the checker chose
                                {{
                                    (draft.verification.verifier_selected ?? [])
                                        .length
                                        ? (
                                              draft.verification
                                                  .verifier_selected ?? []
                                          )
                                              .map(letter)
                                              .join(', ')
                                        : 'no answer'
                                }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ draft.verification.reasoning }}
                            </p>
                            <p
                                v-if="draft.is_code_output"
                                class="text-xs text-muted-foreground"
                            >
                                Run the snippet to confirm before adding it.
                            </p>
                        </div>
                    </div>

                    <QuestionView
                        :type="draft.type"
                        :body="draft.body"
                        :options="draft.options"
                        :code-language="draft.code_language"
                        show-answers
                    />

                    <details
                        v-if="
                            draft.model_answer ||
                            draft.rubric ||
                            draft.explanation
                        "
                        class="group rounded-lg bg-muted/40 text-sm"
                    >
                        <summary
                            class="cursor-pointer px-3 py-2 font-medium select-none"
                        >
                            {{
                                draft.model_answer
                                    ? 'Model answer, rubric & explanation'
                                    : 'Explanation'
                            }}
                        </summary>
                        <div class="space-y-3 px-3 pb-3">
                            <div v-if="draft.model_answer">
                                <p
                                    class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                                >
                                    Model answer
                                </p>
                                <Markdown :source="draft.model_answer" />
                            </div>
                            <div v-if="draft.rubric">
                                <p
                                    class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                                >
                                    Rubric
                                </p>
                                <Markdown :source="draft.rubric" />
                            </div>
                            <div v-if="draft.explanation">
                                <p
                                    class="mb-1 text-xs font-medium text-muted-foreground uppercase"
                                >
                                    Explanation
                                </p>
                                <Markdown :source="draft.explanation" />
                            </div>
                        </div>
                    </details>
                </div>
            </article>
        </template>
    </div>

    <DraftEditDialog
        v-model:open="editOpen"
        :draft="editing"
        :scoring-policies="scoringPolicies"
        :code-languages="codeLanguages"
        :difficulties="difficulties"
        @save="saveEdit"
    />
</template>
