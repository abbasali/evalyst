<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BarChart3, CheckCircle2 } from '@lucide/vue';
import { onMounted } from 'vue';
import CopyButton from '@/components/CopyButton.vue';
import CompletionNotice from '@/components/student/CompletionNotice.vue';
import { Button } from '@/components/ui/button';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { formatInCourseTz } from '@/lib/datetime';

const props = defineProps<{
    submittedAt: string | null;
    autoSubmitted: boolean;
    timezone: string;
    resultsUrl: string | null;
}>();

onMounted(() => {
    if (document.fullscreenElement) {
        void document.exitFullscreen().catch(() => undefined);
    }
});
</script>

<template>
    <Head title="Submitted" />

    <StudentLayout>
        <div class="mx-auto mt-6 max-w-md space-y-6 text-center sm:mt-12">
            <div
                class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950"
            >
                <CheckCircle2
                    class="size-7 text-emerald-600 dark:text-emerald-400"
                />
            </div>
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    Your quiz is submitted
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        props.autoSubmitted
                            ? 'Submitted automatically when time ran out'
                            : 'Submitted'
                    }}
                    {{ formatInCourseTz(submittedAt, undefined, timezone) }}.
                </p>
            </div>

            <CompletionNotice
                v-if="!resultsUrl"
                message="You've completed this quiz. Your instructor will grade it."
            />
            <div
                v-else
                class="space-y-2 rounded-xl border bg-background p-5 text-left"
            >
                <p class="text-sm font-medium">Your results link</p>
                <div
                    class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2"
                >
                    <span class="min-w-0 flex-1 truncate font-mono text-xs">
                        {{ resultsUrl }}
                    </span>
                    <CopyButton :value="resultsUrl" label="Copy results link" />
                </div>
                <Button as-child class="w-full">
                    <a :href="resultsUrl"><BarChart3 /> View my results</a>
                </Button>
                <p class="text-xs text-muted-foreground">
                    Save this link. Your results appear there once your
                    instructor releases them; the page updates by itself.
                </p>
            </div>
        </div>
    </StudentLayout>
</template>
