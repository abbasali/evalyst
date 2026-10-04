<script setup lang="ts">
import { Toaster } from '@/components/ui/sonner';
import { useAppearance } from '@/composables/useAppearance';

/**
 * Toasts coloured by type (success, error, warning, info), in light or dark to match the app.
 */
const { resolvedAppearance } = useAppearance();
</script>

<template>
    <Toaster rich-colors close-button :theme="resolvedAppearance" />
</template>
