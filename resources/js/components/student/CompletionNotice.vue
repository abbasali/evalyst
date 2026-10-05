<script setup lang="ts">
import { CheckCircle2 } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';

/** Shown instead of a results link when the instructor doesn't release results to students. */
defineProps<{ message: string }>();
</script>

<template>
    <Alert variant="success" class="text-left">
        <CheckCircle2 />
        <AlertDescription class="text-sm font-medium">
            {{ message }}
        </AlertDescription>
    </Alert>
</template>
