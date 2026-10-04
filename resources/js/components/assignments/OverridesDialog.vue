<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCourse } from '@/composables/useCourse';
import { overrides as overridesRoute } from '@/routes/assignments/participants';
import type { Option, ParticipantOverrides as Overrides } from '@/types';

const props = defineProps<{
    assignmentId: number;
    participant: { id: number; name: string; overrides: Overrides } | null;
    lateOverrides: Option[];
}>();

const open = defineModel<boolean>('open', { required: true });
const { slug, course } = useCourse();

const form = useForm<Overrides>({
    deadline_override_at: '',
    late_override: '',
    penalty_waived: false,
    penalty_override: '',
    override_note: '',
});

watch(
    () => props.participant,
    (participant) => {
        if (participant) {
            form.defaults({ ...participant.overrides, override_note: '' });
            form.reset();
            form.clearErrors();
        }
    },
    { immediate: true },
);

function submit() {
    if (!props.participant) {
        return;
    }

    form.transform((data) => ({
        ...data,
        penalty_override:
            data.penalty_override === '' ? null : data.penalty_override,
    })).submit(
        overridesRoute([slug.value, props.assignmentId, props.participant.id]),
        { preserveScroll: true, onSuccess: () => (open.value = false) },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Overrides for {{ participant?.name }}</DialogTitle>
                <DialogDescription>
                    Applies straight away, even after grading. The current
                    submission’s lateness and score are recalculated.
                </DialogDescription>
            </DialogHeader>

            <form
                id="overrides-form"
                class="space-y-4"
                @submit.prevent="submit"
            >
                <div class="space-y-1.5">
                    <Label for="deadline_override_at">
                        Personal deadline
                        <span class="font-normal text-muted-foreground">
                            ({{ course.timezone }})
                        </span>
                    </Label>
                    <Input
                        id="deadline_override_at"
                        v-model="form.deadline_override_at"
                        type="datetime-local"
                    />
                    <p class="text-xs text-muted-foreground">
                        Empty = the assignment's deadline.
                    </p>
                    <InputError :message="form.errors.deadline_override_at" />
                </div>
                <div class="space-y-1.5">
                    <Label for="late_override">Late submissions</Label>
                    <NativeSelect
                        id="late_override"
                        v-model="form.late_override"
                    >
                        <option value="">Follow the assignment's policy</option>
                        <option
                            v-for="option in lateOverrides"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                </div>
                <div class="grid grid-cols-2 items-end gap-3">
                    <label class="flex h-9 items-center gap-2 text-sm">
                        <Checkbox v-model="form.penalty_waived" />
                        Waive the penalty
                    </label>
                    <div class="space-y-1.5">
                        <Label for="penalty_override">Fixed penalty</Label>
                        <Input
                            id="penalty_override"
                            v-model="form.penalty_override"
                            type="number"
                            min="0"
                            step="0.5"
                            placeholder="Calculated"
                            :disabled="form.penalty_waived"
                        />
                        <InputError :message="form.errors.penalty_override" />
                    </div>
                </div>
                <div class="space-y-1.5">
                    <Label for="override_note">Reason</Label>
                    <Textarea
                        id="override_note"
                        v-model="form.override_note"
                        rows="2"
                        placeholder="Medical certificate for 3–5 Oct"
                        required
                    />
                    <InputError :message="form.errors.override_note" />
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="open = false"
                    >Cancel</Button
                >
                <Button
                    type="submit"
                    form="overrides-form"
                    :disabled="form.processing"
                >
                    <Spinner v-if="form.processing" /> Save overrides
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
