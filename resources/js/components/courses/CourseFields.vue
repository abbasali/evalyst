<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';

withDefaults(
    defineProps<{
        errors: Record<string, string | undefined>;
        timezones: string[];
        name?: string;
        description?: string | null;
        timezone?: string;
    }>(),
    { name: '', description: '', timezone: 'Asia/Kolkata' },
);
</script>

<template>
    <div class="grid gap-2">
        <Label for="name">Course name</Label>
        <Input
            id="name"
            name="name"
            :default-value="name"
            placeholder="e.g. Web Development — Batch 12"
            required
            maxlength="100"
        />
        <InputError :message="errors.name" />
    </div>

    <div class="grid gap-2">
        <Label for="description">
            Description
            <span class="font-normal text-muted-foreground">(optional)</span>
        </Label>
        <Textarea
            id="description"
            name="description"
            :default-value="description ?? ''"
            rows="3"
            maxlength="2000"
            placeholder="What is this course about?"
        />
        <InputError :message="errors.description" />
    </div>

    <div class="grid gap-2">
        <Label for="timezone">Timezone</Label>
        <NativeSelect id="timezone" name="timezone" :default-value="timezone">
            <option v-for="zone in timezones" :key="zone" :value="zone">
                {{ zone.replaceAll('_', ' ') }}
            </option>
        </NativeSelect>
        <p class="text-xs text-muted-foreground">
            Deadlines and times are shown in this timezone.
        </p>
        <InputError :message="errors.timezone" />
    </div>
</template>
