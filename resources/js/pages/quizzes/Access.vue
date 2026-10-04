<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Download,
    KeyRound,
    MoreHorizontal,
    Printer,
    RefreshCw,
    Trash2,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CopyButton from '@/components/CopyButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import AddStudentsDialog from '@/components/quizzes/AddStudentsDialog.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCourse } from '@/composables/useCourse';
import { formatInCourseTz } from '@/lib/datetime';
import { index } from '@/routes/quizzes';
import { csv, print } from '@/routes/quizzes/codes';
import { destroy, regenerateCode } from '@/routes/quizzes/participants';
import { rotate } from '@/routes/quizzes/shared-code';
import type {
    ParticipantRow,
    ParticipantStatus,
    QuizShellProps,
    RosterStudent,
    Team,
} from '@/types';

const props = defineProps<
    QuizShellProps & {
        sharedCode: string | null;
        joinUrl: string;
        participants: ParticipantRow[];
        roster: RosterStudent[];
    }
>();

defineOptions({
    layout: (props: { currentTeam: Team } & QuizShellProps) => ({
        breadcrumbs: [
            { title: 'Quizzes', href: index(props.currentTeam.slug) },
            { title: props.quiz.title, href: '#' },
        ],
    }),
});

const { slug } = useCourse();
const args = computed(() => [slug.value, props.quiz.id] as [string, number]);
const isRoster = computed(() => props.quiz.access_mode === 'roster');
const canManage = computed(() => props.quiz.can.update);

const addOpen = ref(false);
const rotateOpen = ref(false);
const processing = ref(false);
const confirming = ref<{
    participant: ParticipantRow;
    action: 'regenerate' | 'remove';
} | null>(null);
const confirmOpen = ref(false);

const statusMeta: Record<
    ParticipantStatus,
    { label: string; variant: 'outline' | 'info' | 'success' }
> = {
    not_started: { label: 'Not started', variant: 'outline' },
    in_progress: { label: 'In progress', variant: 'info' },
    submitted: { label: 'Submitted', variant: 'success' },
};

function ask(participant: ParticipantRow, action: 'regenerate' | 'remove') {
    confirming.value = { participant, action };
    confirmOpen.value = true;
}

const visitOptions = {
    preserveScroll: true,
    onStart: () => (processing.value = true),
    onFinish: () => (processing.value = false),
};

function confirm() {
    if (!confirming.value) {
        return;
    }

    const { participant, action } = confirming.value;
    const target = [...args.value, participant.id] as [string, number, number];

    router.visit(
        action === 'regenerate' ? regenerateCode(target) : destroy(target),
        { ...visitOptions, onSuccess: () => (confirmOpen.value = false) },
    );
}

function rotateCode() {
    router.post(
        rotate.url(args.value),
        {},
        { ...visitOptions, onSuccess: () => (rotateOpen.value = false) },
    );
}
</script>

<template>
    <Head :title="`${quiz.title} · Access`" />

    <QuizShell :quiz="quiz" :checklist="checklist" tab="access">
        <!-- Shared code -->
        <section
            v-if="!isRoster"
            class="flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="space-y-2">
                <p class="flex items-center gap-2 text-sm font-medium">
                    <KeyRound class="size-4" /> Shared join code
                </p>
                <div class="flex items-center gap-2">
                    <span
                        class="rounded-lg bg-muted px-4 py-2 font-mono text-3xl font-semibold tracking-[0.3em]"
                    >
                        {{ sharedCode ?? '——' }}
                    </span>
                    <CopyButton
                        v-if="sharedCode"
                        :value="sharedCode"
                        label="Copy code"
                    />
                </div>
                <p class="text-sm text-muted-foreground">
                    Students go to
                    <span class="font-medium text-foreground">{{
                        joinUrl
                    }}</span>
                    <CopyButton :value="joinUrl" label="Copy join link" />
                    and enter this code with their name and roll number.
                </p>
            </div>
            <Button
                v-if="canManage && sharedCode"
                variant="outline"
                class="shrink-0"
                @click="rotateOpen = true"
            >
                <RefreshCw /> Rotate code
            </Button>
        </section>

        <!-- Roster -->
        <div
            v-else-if="participants.length"
            class="flex flex-wrap items-center justify-between gap-3"
        >
            <p class="text-sm text-muted-foreground">
                Students go to
                <span class="font-medium text-foreground">{{ joinUrl }}</span>
                and enter their personal code.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <a :href="print.url(args)" target="_blank">
                        <Printer /> Print codes
                    </a>
                </Button>
                <Button variant="outline" as-child>
                    <a :href="csv.url(args)"><Download /> CSV</a>
                </Button>
                <Button v-if="canManage" @click="addOpen = true">
                    <UserPlus /> Add students
                </Button>
            </div>
        </div>

        <EmptyState
            v-if="isRoster && participants.length === 0"
            :icon="Users"
            title="No students added yet"
            description="Pick students from your course roster. Each one gets a personal code to join this quiz."
        >
            <Button v-if="canManage" @click="addOpen = true">
                <UserPlus /> Add students
            </Button>
        </EmptyState>

        <div
            v-if="participants.length"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Roll no.</th>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th v-if="isRoster" class="px-4 py-3 font-medium">
                            Code
                        </th>
                        <th
                            v-else
                            class="hidden px-4 py-3 font-medium sm:table-cell"
                        >
                            Joined
                        </th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th v-if="isRoster && canManage" class="w-12 px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="participant in participants"
                        :key="participant.id"
                        class="transition-colors hover:bg-muted/30"
                    >
                        <td class="px-4 py-2.5 font-mono text-xs">
                            {{ participant.roll_number }}
                        </td>
                        <td class="px-4 py-2.5 font-medium">
                            {{ participant.name }}
                        </td>
                        <td v-if="isRoster" class="px-4 py-2.5">
                            <span class="inline-flex items-center gap-1">
                                <span class="font-mono tracking-wider">
                                    {{ participant.access_code }}
                                </span>
                                <CopyButton
                                    v-if="participant.access_code"
                                    :value="participant.access_code"
                                    :label="`Copy code for ${participant.name}`"
                                />
                            </span>
                        </td>
                        <td
                            v-else
                            class="hidden px-4 py-2.5 text-muted-foreground sm:table-cell"
                        >
                            {{ formatInCourseTz(participant.joined_at) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <Badge
                                :variant="
                                    statusMeta[participant.status].variant
                                "
                            >
                                {{ statusMeta[participant.status].label }}
                            </Badge>
                        </td>
                        <td
                            v-if="isRoster && canManage"
                            class="px-2 py-2 text-right"
                        >
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        :aria-label="`Actions for ${participant.name}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="ask(participant, 'regenerate')"
                                    >
                                        <RefreshCw /> New code
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            participant.status === 'not_started'
                                        "
                                        variant="destructive"
                                        @click="ask(participant, 'remove')"
                                    >
                                        <Trash2 /> Remove from quiz
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p
            v-else-if="!isRoster"
            class="rounded-xl border border-dashed px-6 py-10 text-center text-sm text-muted-foreground"
        >
            Nobody has joined yet. Students appear here as they join with the
            code.
        </p>

        <AddStudentsDialog
            v-if="isRoster"
            v-model:open="addOpen"
            :quiz-id="quiz.id"
            :roster="roster"
            :added-ids="
                participants.map((participant) => participant.student_id)
            "
        />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="
                confirming?.action === 'remove'
                    ? 'Remove this student?'
                    : 'Issue a new code?'
            "
            :description="
                confirming
                    ? confirming.action === 'remove'
                        ? `${confirming.participant.name} will no longer be able to join this quiz.`
                        : `${confirming.participant.name}'s current code (${confirming.participant.access_code}) stops working straight away.`
                    : ''
            "
            :confirm-label="
                confirming?.action === 'remove' ? 'Remove' : 'New code'
            "
            :destructive="confirming?.action === 'remove'"
            :processing="processing"
            @confirm="confirm"
        />
        <ConfirmDialog
            v-model:open="rotateOpen"
            title="Rotate the join code?"
            description="New students will need the new code. Students who already joined can still resume."
            confirm-label="Rotate code"
            :processing="processing"
            @confirm="rotateCode"
        />
    </QuizShell>
</template>
