<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccessPanel from '@/components/assessments/AccessPanel.vue';
import AssignmentShell from '@/components/assignments/AssignmentShell.vue';
import { index } from '@/routes/assignments';
import type {
    AssignmentShellProps,
    ParticipantRow,
    RosterStudent,
    Team,
} from '@/types';

defineProps<
    AssignmentShellProps & {
        sharedCode: string | null;
        joinUrl: string;
        participants: ParticipantRow[];
        roster: RosterStudent[];
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
</script>

<template>
    <Head :title="`${assignment.title} · Access`" />

    <AssignmentShell
        :assignment="assignment"
        :checklist="checklist"
        tab="access"
    >
        <AccessPanel
            kind="assignment"
            :assessment="assignment"
            :shared-code="sharedCode"
            :join-url="joinUrl"
            :participants="participants"
            :roster="roster"
        />
    </AssignmentShell>
</template>
