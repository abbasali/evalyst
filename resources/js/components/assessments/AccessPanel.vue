<script setup lang="ts">
import { router } from '@inertiajs/vue3';
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
import * as assignmentCodes from '@/routes/assignments/codes';
import * as assignmentParticipants from '@/routes/assignments/participants';
import * as assignmentSharedCode from '@/routes/assignments/shared-code';
import * as quizCodes from '@/routes/quizzes/codes';
import * as quizParticipants from '@/routes/quizzes/participants';
import * as quizSharedCode from '@/routes/quizzes/shared-code';
import type { ParticipantRow, ParticipantStatus, RosterStudent } from '@/types';

/**
 * Who can join and how: the shared code, or the roster with personal codes. Used by the
 * Access tab of quizzes and assignments.
 */
const props = defineProps<{
    kind: 'quiz' | 'assignment';
    assessment: {
        id: number;
        access_mode: 'roster' | 'shared_code';
        can: { update: boolean };
    };
    sharedCode: string | null;
    joinUrl: string;
    participants: ParticipantRow[];
    roster: RosterStudent[];
}>();

const routes = computed(() =>
    props.kind === 'quiz'
        ? {
              csv: quizCodes.csv,
              print: quizCodes.print,
              destroy: quizParticipants.destroy,
              regenerateCode: quizParticipants.regenerateCode,
              rotate: quizSharedCode.rotate,
          }
        : {
              csv: assignmentCodes.csv,
              print: assignmentCodes.print,
              destroy: assignmentParticipants.destroy,
              regenerateCode: assignmentParticipants.regenerateCode,
              rotate: assignmentSharedCode.rotate,
          },
);

const { slug } = useCourse();
const args = computed(
    () => [slug.value, props.assessment.id] as [string, number],
);
const isRoster = computed(() => props.assessment.access_mode === 'roster');
const canManage = computed(() => props.assessment.can.update);

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
        action === 'regenerate'
            ? routes.value.regenerateCode(target)
            : routes.value.destroy(target),
        { ...visitOptions, onSuccess: () => (confirmOpen.value = false) },
    );
}

function rotateCode() {
    router.post(
        routes.value.rotate.url(args.value),
        {},
        { ...visitOptions, onSuccess: () => (rotateOpen.value = false) },
    );
}
</script>

<template>
    <div class="flex flex-col gap-5">
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
                    <a :href="routes.print.url(args)" target="_blank">
                        <Printer /> Print codes
                    </a>
                </Button>
                <Button variant="outline" as-child>
                    <a :href="routes.csv.url(args)"><Download /> CSV</a>
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
            :description="`Pick students from your course roster. Each one gets a personal code to join this ${kind}.`"
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
                                        <Trash2 /> Remove from {{ kind }}
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
            :kind="kind"
            :assessment-id="assessment.id"
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
                        ? `${confirming.participant.name} will no longer be able to join this ${kind}.`
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
    </div>
</template>
