<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import AppToaster from '@/components/AppToaster.vue';

/**
 * Minimal layout for students: no sidebar, course + quiz title, and a slot for the timer.
 */
const props = withDefaults(
    defineProps<{ wide?: boolean; title?: string; course?: string }>(),
    { wide: false, title: undefined, course: undefined },
);

const page = usePage();
const context = computed(
    () =>
        page.props.studentContext as
            | {
                  course: string;
                  title: string;
                  name: string;
                  roll_number: string;
              }
            | undefined,
);
</script>

<template>
    <div class="flex min-h-svh flex-col bg-muted/30">
        <header
            class="sticky top-0 z-30 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80"
        >
            <div
                :class="[
                    'mx-auto flex h-14 items-center gap-3 px-4',
                    wide ? 'max-w-6xl' : 'max-w-3xl',
                ]"
            >
                <AppLogoIcon class="size-6 shrink-0 fill-current" />
                <div class="min-w-0 flex-1 leading-tight">
                    <p
                        v-if="props.title ?? context"
                        class="truncate text-sm font-semibold"
                    >
                        {{ props.title ?? context?.title }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ props.course ?? context?.course ?? 'Evalyst' }}
                    </p>
                </div>
                <slot name="header" />
            </div>
        </header>
        <main
            :class="[
                'mx-auto w-full flex-1 px-4 py-6 md:py-8',
                wide ? 'max-w-6xl' : 'max-w-3xl',
            ]"
        >
            <slot />
        </main>
        <AppToaster />
    </div>
</template>
