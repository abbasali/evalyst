<script setup lang="ts">
import { computed } from 'vue';
import { renderMarkdown } from '@/lib/markdown';
import { cn } from '@/lib/utils';

const props = defineProps<{
    source: string | null | undefined;
    inline?: boolean;
    class?: string;
}>();

const html = computed(() => renderMarkdown(props.source ?? '', props.inline));
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -- sanitised by DOMPurify -->
    <span v-if="inline" :class="cn('md md-inline', props.class)" v-html="html" />
    <!-- eslint-disable-next-line vue/no-v-html -- sanitised by DOMPurify -->
    <div v-else :class="cn('md', props.class)" v-html="html" />
</template>
