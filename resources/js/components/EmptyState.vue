<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    icon: Component;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center"
    >
        <div
            class="flex size-11 items-center justify-center rounded-full bg-muted"
        >
            <component :is="icon" class="size-5 text-muted-foreground" />
        </div>
        <div class="space-y-1">
            <h3 class="font-medium">{{ title }}</h3>
            <p
                v-if="description"
                class="max-w-sm text-sm text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div
            v-if="$slots.default"
            class="mt-2 flex flex-wrap justify-center gap-2"
        >
            <slot />
        </div>
    </div>
</template>
