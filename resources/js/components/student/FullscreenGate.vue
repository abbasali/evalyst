<script setup lang="ts">
import { Maximize } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Covers the quiz until the browser is fullscreen. Browsers without the Fullscreen API
 * (e.g. iPhone Safari) are let through. Emits `exited` each time the student leaves fullscreen.
 */
const emit = defineEmits<{ exited: [] }>();

const supported =
    typeof document !== 'undefined' && document.fullscreenEnabled === true;
const isFullscreen = ref(!supported || !!document.fullscreenElement);
const failed = ref(false);

function onChange() {
    const now = !!document.fullscreenElement;

    if (isFullscreen.value && !now) {
        emit('exited');
    }

    isFullscreen.value = now;
}

async function enter() {
    try {
        await document.documentElement.requestFullscreen();
        failed.value = false;
    } catch {
        failed.value = true;
    }
}

onMounted(() => document.addEventListener('fullscreenchange', onChange));
onBeforeUnmount(() =>
    document.removeEventListener('fullscreenchange', onChange),
);
</script>

<template>
    <div
        v-if="!isFullscreen"
        class="fixed inset-0 z-40 flex items-center justify-center bg-background/98 p-6 backdrop-blur"
        role="dialog"
        aria-modal="true"
        aria-labelledby="fullscreen-title"
    >
        <div class="max-w-sm space-y-4 text-center">
            <div
                class="mx-auto flex size-12 items-center justify-center rounded-full bg-muted"
            >
                <Maximize class="size-5" />
            </div>
            <h2 id="fullscreen-title" class="text-lg font-semibold">
                This quiz runs in fullscreen
            </h2>
            <p class="text-sm text-muted-foreground">
                Your timer keeps running. Leaving fullscreen is recorded and
                visible to your instructor.
            </p>
            <Button size="lg" class="w-full" @click="enter">
                Go fullscreen and continue
            </Button>
            <p v-if="failed" class="text-xs text-destructive">
                Your browser blocked fullscreen. Try again, or use a different
                browser.
            </p>
        </div>
    </div>
</template>
