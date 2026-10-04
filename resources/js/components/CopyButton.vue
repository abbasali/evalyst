<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';

const props = withDefaults(
    defineProps<{ value: string; label?: string; size?: 'icon' | 'sm' }>(),
    { label: 'Copy', size: 'icon' },
);

const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error('Could not copy. Select the text and copy it manually.');
    }
}
</script>

<template>
    <Button
        type="button"
        variant="ghost"
        :size="size"
        :class="size === 'icon' ? 'size-7' : ''"
        :aria-label="label"
        @click="copy"
    >
        <Check v-if="copied" class="text-emerald-600" />
        <Copy v-else />
        <span v-if="size !== 'icon'">{{ copied ? 'Copied' : label }}</span>
    </Button>
</template>
