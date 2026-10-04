<script setup lang="ts">
/**
 * Faint, repeated "name · roll number" across the quiz so photos and screenshots are traceable.
 */
defineProps<{ text: string }>();
</script>

<template>
    <div
        class="pointer-events-none fixed inset-0 z-20 overflow-hidden select-none print:hidden"
        aria-hidden="true"
    >
        <div
            class="absolute -inset-1/2 flex -rotate-[24deg] flex-wrap content-start gap-x-24 gap-y-20 opacity-[0.06] dark:opacity-[0.08]"
        >
            <span
                v-for="index in 160"
                :key="index"
                class="text-sm font-semibold whitespace-nowrap"
            >
                {{ text }}
            </span>
        </div>
    </div>
</template>
