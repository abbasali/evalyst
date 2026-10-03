<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Construction } from '@lucide/vue';

const props = defineProps<{ section: string }>();

defineOptions({
    layout: (props: { section: string }) => ({
        breadcrumbs: [{ title: props.section, href: '#' }],
    }),
});
</script>

<template>
    <Head :title="props.section" />
    <div
        class="flex flex-1 flex-col items-center justify-center gap-3 p-10 text-center"
    >
        <Construction class="size-10 text-muted-foreground" />
        <h1 class="text-lg font-semibold">{{ props.section }}</h1>
        <p class="max-w-sm text-sm text-muted-foreground">
            This section is coming soon.
        </p>
    </div>
</template>
