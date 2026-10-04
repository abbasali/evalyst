<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AccessPanel from '@/components/assessments/AccessPanel.vue';
import QuizShell from '@/components/quizzes/QuizShell.vue';
import { index } from '@/routes/quizzes';
import type {
    ParticipantRow,
    QuizShellProps,
    RosterStudent,
    Team,
} from '@/types';

defineProps<
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
</script>

<template>
    <Head :title="`${quiz.title} · Access`" />

    <QuizShell :quiz="quiz" :checklist="checklist" tab="access">
        <AccessPanel
            kind="quiz"
            :assessment="quiz"
            :shared-code="sharedCode"
            :join-url="joinUrl"
            :participants="participants"
            :roster="roster"
        />
    </QuizShell>
</template>
