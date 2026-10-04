<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Bot,
    ListChecks,
    Plus,
    Trash2,
    Wrench,
} from '@lucide/vue';
import { computed } from 'vue';
import AssignmentShell from '@/components/assignments/AssignmentShell.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCourse } from '@/composables/useCourse';
import { marks } from '@/lib/grading';
import { index } from '@/routes/assignments';
import { update } from '@/routes/assignments/rules';
import type {
    AssignmentRuleForm,
    AssignmentShellProps,
    AutomatedCheck,
    Option,
    Team,
} from '@/types';

const props = defineProps<
    AssignmentShellProps & {
        rules: Omit<AssignmentRuleForm, 'key'>[];
        checks: Option[];
        gradedCount: number;
    }
>();

defineOptions({
    layout: (props: { currentTeam: Team } & AssignmentShellProps) => ({
        breadcrumbs: [
            { title: 'Assignments', href: index(props.currentTeam.slug) },
            { title: props.assignment.title, href: '#' },
        ],
    }),
});

const { slug } = useCourse();
let nextKey = 0;
const keyed = (rule: Omit<AssignmentRuleForm, 'key'>): AssignmentRuleForm => ({
    ...rule,
    config: { ...rule.config },
    key: `k${nextKey++}`,
});

const form = useForm<{ rules: AssignmentRuleForm[] }>({
    rules: props.rules.map(keyed),
});

const canEdit = computed(() => props.assignment.can.update);
const total = computed(() =>
    form.rules.reduce((sum, rule) => sum + (Number(rule.marks) || 0), 0),
);
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

/** Defaults and help for each automated check. */
const checkMeta: Record<
    AutomatedCheck,
    { defaults: AssignmentRuleForm['config']; help: string }
> = {
    min_commits: {
        defaults: { min: 10 },
        help: 'Non-merge commits. Partial credit is proportional.',
    },
    min_commit_days: {
        defaults: { min: 3 },
        help: 'Distinct days with commits, in the course timezone. Commit dates come from the student’s machine, so treat them as a hint.',
    },
    commit_message_pattern: {
        defaults: {
            pattern:
                '^(feat|fix|chore|docs|refactor|test|style)(\\(.+\\))?: .+',
            min_ratio: 0.8,
        },
        help: 'The share of commit messages that must match. Partial credit up to that share.',
    },
    path_exists: {
        defaults: { glob: 'tests/Feature/*Test.php', min_matches: 1 },
        help: 'Checked against every file in the repo.',
    },
    path_absent: {
        defaults: { glob: 'vendor/**' },
        help: 'Catches committed junk such as vendor/ or .env.',
    },
    file_contains: {
        defaults: {
            glob: 'app/Http/Requests/*.php',
            pattern: 'function rules',
        },
        help: 'Passes when at least one matching file contains the pattern (a regular expression).',
    },
};

const presets = [
    {
        label: 'Conventional Commits',
        pattern: '^(feat|fix|chore|docs|refactor|test|style)(\\(.+\\))?: .+',
    },
    { label: 'Imperative, 10+ characters', pattern: '^[A-Z][a-z]+ .{8,}' },
];

function add(kind: 'automated' | 'ai') {
    form.rules.push(
        keyed(
            kind === 'ai'
                ? {
                      id: null,
                      kind,
                      title: '',
                      description: '',
                      check: null,
                      config: {},
                      marks: 5,
                  }
                : {
                      id: null,
                      kind,
                      title: 'Minimum commits',
                      description: null,
                      check: 'min_commits',
                      config: { ...checkMeta.min_commits.defaults },
                      marks: 2,
                  },
        ),
    );
}

function changeCheck(rule: AssignmentRuleForm, check: AutomatedCheck) {
    const previous = props.checks.find((item) => item.value === rule.check);

    rule.check = check;
    rule.config = { ...checkMeta[check].defaults };

    if (!rule.title || rule.title === previous?.label) {
        rule.title =
            props.checks.find((item) => item.value === check)?.label ?? '';
    }
}

function move(index: number, by: -1 | 1) {
    const target = index + by;

    if (target < 0 || target >= form.rules.length) {
        return;
    }

    const rules = [...form.rules];
    [rules[index], rules[target]] = [rules[target], rules[index]];
    form.rules = rules;
}

function remove(index: number) {
    form.rules = form.rules.filter((_, i) => i !== index);
}

function ratioPercent(rule: AssignmentRuleForm): number {
    return Math.round(Number(rule.config.min_ratio ?? 0) * 100);
}

function save() {
    form.transform((data) => ({
        rules: data.rules.map(
            ({ key: _key, has_results: _results, ...rule }) => rule,
        ),
    })).submit(update([slug.value, props.assignment.id]), {
        preserveScroll: true,
        onSuccess: () => {
            form.rules = props.rules.map(keyed);
            form.defaults();
        },
    });
}

const fieldError = (index: number, field: string) =>
    errors.value[`rules.${index}.${field}`];
</script>

<template>
    <Head :title="`${assignment.title} · Rules`" />

    <AssignmentShell
        :assignment="assignment"
        :checklist="checklist"
        tab="rules"
    >
        <div class="max-w-3xl space-y-4">
            <p class="text-sm text-muted-foreground">
                Each rule earns marks. <strong>Automated checks</strong> look at
                the repo’s files and commits. <strong>AI rules</strong> are
                judged by the AI against your description and the problem
                statement.
            </p>

            <p
                v-if="gradedCount > 0"
                class="rounded-lg border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm"
            >
                {{ gradedCount }}
                {{ gradedCount === 1 ? 'submission has' : 'submissions have' }}
                been graded with these rules. Saving changes doesn’t regrade
                them; regrade from the Submissions tab.
            </p>

            <EmptyState
                v-if="form.rules.length === 0"
                :icon="ListChecks"
                title="No rules yet"
                description="Add automated checks (commits, files) and AI rules (code quality, requirements met). The total is the assignment's maximum score."
            />

            <ol class="space-y-3">
                <li
                    v-for="(rule, i) in form.rules"
                    :key="rule.key"
                    class="space-y-3 rounded-xl border p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <Badge
                            :variant="rule.kind === 'ai' ? 'info' : 'secondary'"
                        >
                            <component
                                :is="rule.kind === 'ai' ? Bot : Wrench"
                                class="size-3"
                            />
                            {{ rule.kind === 'ai' ? 'AI rule' : 'Automated' }}
                        </Badge>
                        <div v-if="canEdit" class="flex items-center gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :disabled="i === 0"
                                aria-label="Move up"
                                @click="move(i, -1)"
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :disabled="i === form.rules.length - 1"
                                aria-label="Move down"
                                @click="move(i, 1)"
                            >
                                <ArrowDown />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :disabled="rule.has_results"
                                :title="
                                    rule.has_results
                                        ? 'This rule has grades and can’t be removed'
                                        : 'Remove'
                                "
                                aria-label="Remove rule"
                                @click="remove(i)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-[1fr_7rem]">
                        <div class="space-y-1.5">
                            <Label :for="`title-${rule.key}`">Title</Label>
                            <Input
                                :id="`title-${rule.key}`"
                                v-model="rule.title"
                                maxlength="150"
                                :placeholder="
                                    rule.kind === 'ai'
                                        ? 'Validation uses Form Requests'
                                        : ''
                                "
                                :disabled="!canEdit"
                            />
                            <InputError :message="fieldError(i, 'title')" />
                        </div>
                        <div class="space-y-1.5">
                            <Label :for="`marks-${rule.key}`">Marks</Label>
                            <Input
                                :id="`marks-${rule.key}`"
                                v-model="rule.marks"
                                type="number"
                                min="0.5"
                                step="0.5"
                                :disabled="!canEdit"
                            />
                            <InputError :message="fieldError(i, 'marks')" />
                        </div>
                    </div>

                    <div v-if="rule.kind === 'ai'" class="space-y-1.5">
                        <Label :for="`description-${rule.key}`">
                            What should the AI judge?
                        </Label>
                        <Textarea
                            :id="`description-${rule.key}`"
                            v-model="rule.description as string"
                            rows="3"
                            placeholder="Every store/update action validates input with a Form Request class."
                            :disabled="!canEdit"
                        />
                        <InputError :message="fieldError(i, 'description')" />
                    </div>

                    <template v-else-if="rule.check">
                        <div class="space-y-1.5">
                            <Label :for="`check-${rule.key}`">Check</Label>
                            <NativeSelect
                                :id="`check-${rule.key}`"
                                :model-value="rule.check"
                                class="max-w-xs"
                                :disabled="!canEdit"
                                @update:model-value="
                                    changeCheck(rule, $event as AutomatedCheck)
                                "
                            >
                                <option
                                    v-for="check in checks"
                                    :key="check.value"
                                    :value="check.value"
                                >
                                    {{ check.label }}
                                </option>
                            </NativeSelect>
                            <p class="text-xs text-muted-foreground">
                                {{ checkMeta[rule.check].help }}
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-if="
                                    rule.check === 'min_commits' ||
                                    rule.check === 'min_commit_days'
                                "
                                class="space-y-1.5"
                            >
                                <Label :for="`min-${rule.key}`">
                                    {{
                                        rule.check === 'min_commits'
                                            ? 'Minimum commits'
                                            : 'Minimum days'
                                    }}
                                </Label>
                                <Input
                                    :id="`min-${rule.key}`"
                                    v-model="rule.config.min"
                                    type="number"
                                    min="1"
                                    :disabled="!canEdit"
                                />
                                <InputError
                                    :message="fieldError(i, 'config.min')"
                                />
                            </div>

                            <div
                                v-if="
                                    rule.check === 'path_exists' ||
                                    rule.check === 'path_absent' ||
                                    rule.check === 'file_contains'
                                "
                                class="space-y-1.5"
                            >
                                <Label :for="`glob-${rule.key}`">
                                    Path or glob
                                </Label>
                                <Input
                                    :id="`glob-${rule.key}`"
                                    v-model="rule.config.glob"
                                    class="font-mono text-xs"
                                    :disabled="!canEdit"
                                />
                                <div
                                    v-if="
                                        rule.check === 'path_absent' && canEdit
                                    "
                                    class="flex flex-wrap gap-1"
                                >
                                    <button
                                        v-for="glob in [
                                            'vendor/**',
                                            'node_modules/**',
                                            '.env',
                                        ]"
                                        :key="glob"
                                        type="button"
                                        class="rounded border px-1.5 font-mono text-xs hover:bg-muted"
                                        @click="rule.config.glob = glob"
                                    >
                                        {{ glob }}
                                    </button>
                                </div>
                                <InputError
                                    :message="fieldError(i, 'config.glob')"
                                />
                            </div>

                            <div
                                v-if="rule.check === 'path_exists'"
                                class="space-y-1.5"
                            >
                                <Label :for="`matches-${rule.key}`">
                                    At least this many files
                                </Label>
                                <Input
                                    :id="`matches-${rule.key}`"
                                    v-model="rule.config.min_matches"
                                    type="number"
                                    min="1"
                                    :disabled="!canEdit"
                                />
                                <InputError
                                    :message="
                                        fieldError(i, 'config.min_matches')
                                    "
                                />
                            </div>

                            <div
                                v-if="
                                    rule.check === 'commit_message_pattern' ||
                                    rule.check === 'file_contains'
                                "
                                class="space-y-1.5 sm:col-span-2"
                            >
                                <Label :for="`pattern-${rule.key}`">
                                    Pattern (regular expression)
                                </Label>
                                <Input
                                    :id="`pattern-${rule.key}`"
                                    v-model="rule.config.pattern"
                                    class="font-mono text-xs"
                                    :disabled="!canEdit"
                                />
                                <div
                                    v-if="
                                        rule.check ===
                                            'commit_message_pattern' && canEdit
                                    "
                                    class="flex flex-wrap gap-1"
                                >
                                    <button
                                        v-for="preset in presets"
                                        :key="preset.label"
                                        type="button"
                                        class="rounded border px-1.5 text-xs hover:bg-muted"
                                        @click="
                                            rule.config.pattern = preset.pattern
                                        "
                                    >
                                        {{ preset.label }}
                                    </button>
                                </div>
                                <InputError
                                    :message="fieldError(i, 'config.pattern')"
                                />
                            </div>

                            <div
                                v-if="rule.check === 'commit_message_pattern'"
                                class="space-y-1.5"
                            >
                                <Label :for="`ratio-${rule.key}`">
                                    Share that must match (%)
                                </Label>
                                <Input
                                    :id="`ratio-${rule.key}`"
                                    :model-value="ratioPercent(rule)"
                                    type="number"
                                    min="1"
                                    max="100"
                                    :disabled="!canEdit"
                                    @update:model-value="
                                        rule.config.min_ratio =
                                            Number($event) / 100
                                    "
                                />
                                <InputError
                                    :message="fieldError(i, 'config.min_ratio')"
                                />
                            </div>
                        </div>
                    </template>
                </li>
            </ol>

            <InputError :message="errors.rules" />

            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t pt-4"
            >
                <div v-if="canEdit" class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="add('automated')"
                    >
                        <Plus /> Automated check
                    </Button>
                    <Button type="button" variant="outline" @click="add('ai')">
                        <Plus /> AI rule
                    </Button>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm">
                        Total
                        <span class="font-semibold tabular-nums">
                            {{ marks(total) }}
                        </span>
                        marks
                    </span>
                    <Button
                        v-if="canEdit"
                        :disabled="form.processing || !form.isDirty"
                        @click="save"
                    >
                        <Spinner v-if="form.processing" /> Save rules
                    </Button>
                </div>
            </div>
        </div>
    </AssignmentShell>
</template>
