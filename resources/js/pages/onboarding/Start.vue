<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import TeamInvitationController from '@/actions/App/Http/Controllers/Teams/TeamInvitationController';
import CourseFields from '@/components/courses/CourseFields.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/teams';
import type { DashboardInvitation } from '@/types';

defineProps<{
    timezones: string[];
    defaultTimezone: string;
    pendingInvitations: DashboardInvitation[];
}>();

defineOptions({
    layout: {
        title: 'Create your first course',
        description:
            'A course holds your question bank, students, quizzes and assignments.',
    },
});

const processingCode = ref<string | null>(null);

const respond = (invitation: DashboardInvitation, accept: boolean) => {
    const action = accept
        ? TeamInvitationController.accept(invitation)
        : TeamInvitationController.decline(invitation);

    router.visit(action, {
        onStart: () => (processingCode.value = invitation.code),
        onFinish: () => (processingCode.value = null),
    });
};
</script>

<template>
    <Head title="Create your first course" />

    <div v-if="pendingInvitations.length" class="space-y-3">
        <p class="text-sm font-medium">You've been invited to</p>
        <div
            v-for="invitation in pendingInvitations"
            :key="invitation.code"
            class="flex items-center justify-between gap-3 rounded-lg border p-3"
        >
            <div class="min-w-0">
                <p class="truncate font-medium">{{ invitation.team.name }}</p>
                <p class="text-xs text-muted-foreground">
                    Invited by {{ invitation.inviterName }}
                </p>
            </div>
            <div class="flex shrink-0 gap-2">
                <Button
                    size="sm"
                    variant="ghost"
                    :disabled="processingCode === invitation.code"
                    @click="respond(invitation, false)"
                >
                    Decline
                </Button>
                <Button
                    size="sm"
                    :disabled="processingCode === invitation.code"
                    @click="respond(invitation, true)"
                >
                    Join
                </Button>
            </div>
        </div>

        <div class="flex items-center gap-3 py-2">
            <Separator class="flex-1" />
            <span class="text-xs text-muted-foreground"
                >or create your own</span
            >
            <Separator class="flex-1" />
        </div>
    </div>

    <Form
        v-bind="store.form()"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
    >
        <CourseFields
            :errors="errors"
            :timezones="timezones"
            :timezone="defaultTimezone"
        />

        <Button type="submit" class="w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Create course
        </Button>
    </Form>
</template>
