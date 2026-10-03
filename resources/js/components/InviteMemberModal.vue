<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as storeInvitation } from '@/routes/teams/invitations';
import type { Team } from '@/types';

type Props = {
    team: Team;
    open: boolean;
};

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const formKey = ref(0);

function handleOpenChange(value: boolean) {
    emit('update:open', value);

    if (!value) {
        formKey.value++;
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="handleOpenChange">
        <DialogContent>
            <Form
                :key="formKey"
                v-bind="storeInvitation.form(props.team.slug)"
                class="space-y-6"
                v-slot="{ errors, processing }"
                @success="emit('update:open', false)"
            >
                <DialogHeader>
                    <DialogTitle>Invite a course member</DialogTitle>
                    <DialogDescription>
                        Instructors in a course share everything: questions,
                        students, quizzes and grading.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="email">Email address</Label>
                        <Input
                            id="email"
                            name="email"
                            data-test="invite-email"
                            type="email"
                            placeholder="colleague@example.com"
                            required
                        />
                        <InputError :message="errors.email" />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary"> Cancel </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        data-test="invite-submit"
                        :disabled="processing"
                    >
                        Send invitation
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
