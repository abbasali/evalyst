<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    quiz: { id: number; title: string };
    courseName: string;
    joinUrl: string;
    cards: { name: string; roll_number: string; access_code: string | null }[];
}>();

function printPage() {
    window.print();
}
</script>

<template>
    <Head :title="`${quiz.title} · Access codes`" />

    <div class="mx-auto max-w-4xl bg-white p-6 text-black print:p-0">
        <div class="mb-6 flex items-start justify-between gap-4 print:mb-4">
            <div>
                <h1 class="text-lg font-semibold">{{ quiz.title }}</h1>
                <p class="text-sm text-neutral-600">
                    {{ courseName }} · {{ cards.length }} access codes
                </p>
            </div>
            <Button class="print:hidden" @click="printPage">
                <Printer /> Print
            </Button>
        </div>

        <p v-if="cards.length === 0" class="text-sm text-neutral-600">
            No students have been added to this quiz yet.
        </p>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 print:grid-cols-3">
            <div
                v-for="card in cards"
                :key="card.roll_number"
                class="break-inside-avoid rounded-lg border border-dashed border-neutral-400 p-3"
            >
                <p class="truncate text-sm font-medium">{{ card.name }}</p>
                <p class="font-mono text-xs text-neutral-600">
                    {{ card.roll_number }}
                </p>
                <p
                    class="my-2 font-mono text-xl font-semibold tracking-[0.2em]"
                >
                    {{ card.access_code }}
                </p>
                <p class="truncate text-[11px] text-neutral-600">
                    Join at {{ joinUrl }}
                </p>
            </div>
        </div>
    </div>
</template>
