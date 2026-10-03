<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { store, update } from '@/routes/students';
import type { Student } from '@/types';

const props = defineProps<{ student: Student | null }>();
const open = defineModel<boolean>('open', { required: true });
const { slug } = useCourse();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <Form
                :key="props.student?.id ?? 'new'"
                v-bind="
                    props.student
                        ? update.form([slug, props.student.id])
                        : store.form(slug)
                "
                class="space-y-6"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                @success="open = false"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{ props.student ? 'Edit student' : 'Add student' }}
                    </DialogTitle>
                    <DialogDescription>
                        Students sign in to assessments with access codes, not
                        accounts.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="roll_number">Roll number</Label>
                        <Input
                            id="roll_number"
                            name="roll_number"
                            :default-value="props.student?.roll_number"
                            placeholder="e.g. CS-001"
                            required
                            v-focus
                        />
                        <InputError :message="errors.roll_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            :default-value="props.student?.name"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">
                            Email
                            <span class="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            :default-value="props.student?.email ?? ''"
                        />
                        <InputError :message="errors.email" />
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ props.student ? 'Save' : 'Add student' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
