<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

const props = defineProps<{
    paginator: Paginated<unknown>;
    noun?: string;
}>();

const previous = computed(() => props.paginator.links[0]?.url ?? null);
const next = computed(
    () => props.paginator.links[props.paginator.links.length - 1]?.url ?? null,
);
</script>

<template>
    <div
        v-if="paginator.total > 0"
        class="flex items-center justify-between gap-4 text-sm text-muted-foreground"
    >
        <p>
            {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
            {{ noun ?? 'results' }}
        </p>
        <div v-if="paginator.last_page > 1" class="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                :disabled="!previous"
                :as="previous ? Link : 'button'"
                :href="previous ?? undefined"
                preserve-scroll
            >
                <ChevronLeft /> Previous
            </Button>
            <span class="tabular-nums">
                {{ paginator.current_page }} / {{ paginator.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!next"
                :as="next ? Link : 'button'"
                :href="next ?? undefined"
                preserve-scroll
            >
                Next <ChevronRight />
            </Button>
        </div>
    </div>
</template>
