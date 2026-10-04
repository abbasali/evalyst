<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useCourse } from '@/composables/useCourse';
import { update } from '@/routes/review/answers';

const props = defineProps<{
    answerId: number;
    maxScore: number;
    score: number | null;
    feedback: string | null;
}>();

const emit = defineEmits<{ saved: []; cancel: [] }>();

const { slug } = useCourse();
const form = useForm({
    score: props.score ?? ('' as number | ''),
    feedback: props.feedback ?? '',
});

function submit() {
    form.submit(update([slug.value, props.answerId]), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <form class="space-y-3" @submit.prevent="submit">
        <div class="grid gap-1.5">
            <Label :for="`score-${answerId}`">
                Score (out of {{ maxScore }})
            </Label>
            <Input
                :id="`score-${answerId}`"
                v-model="form.score"
                v-focus
                type="number"
                min="0"
                :max="maxScore"
                step="0.5"
                class="w-28"
                required
            />
            <InputError :message="form.errors.score" />
        </div>
        <div class="grid gap-1.5">
            <Label :for="`feedback-${answerId}`"
                >Feedback for the student</Label
            >
            <Textarea
                :id="`feedback-${answerId}`"
                v-model="form.feedback"
                rows="4"
                placeholder="What was good, and what was missing?"
            />
            <InputError :message="form.errors.feedback" />
        </div>
        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" /> Publish grade
            </Button>
            <Button type="button" variant="ghost" @click="emit('cancel')">
                Cancel
            </Button>
        </div>
    </form>
</template>
