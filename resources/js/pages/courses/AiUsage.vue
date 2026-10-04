<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { formatInCourseTz } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { aiUsage } from '@/routes';
import type { Team } from '@/types';

type Summary = {
    cost: number;
    runs: number;
    failures: number;
    purposes: { purpose: string; runs: number; tokens: number; cost: number }[];
};

defineProps<{
    model: string;
    month: Summary;
    allTime: Summary;
    expensive: {
        id: number;
        purpose: string;
        subject: string;
        model: string;
        tokens: number;
        cost: number;
        succeeded: boolean;
        created_at: string | null;
    }[];
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            { title: 'AI usage', href: aiUsage(props.currentTeam.slug) },
        ],
    }),
});

const period = ref<'month' | 'allTime'>('month');
const usd = (value: number) =>
    value < 0.01 && value > 0 ? '< $0.01' : `$${value.toFixed(2)}`;
</script>

<template>
    <Head title="AI usage" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="AI usage"
            :description="`What this course has spent on AI. Every call uses ${model}.`"
        />

        <div class="flex gap-1 border-b">
            <button
                v-for="tab in [
                    { key: 'month', label: 'This month' },
                    { key: 'allTime', label: 'All time' },
                ] as const"
                :key="tab.key"
                type="button"
                :class="
                    cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
                        period === tab.key
                            ? 'border-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )
                "
                @click="period = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-xl border px-4 py-3">
                <p class="text-xs text-muted-foreground">Spent</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ usd((period === 'month' ? month : allTime).cost) }}
                </p>
            </div>
            <div class="rounded-xl border px-4 py-3">
                <p class="text-xs text-muted-foreground">AI calls</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ (period === 'month' ? month : allTime).runs }}
                </p>
            </div>
            <div class="rounded-xl border px-4 py-3">
                <p class="text-xs text-muted-foreground">Failed calls</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ (period === 'month' ? month : allTime).failures }}
                </p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Used for</th>
                        <th class="px-4 py-3 text-right font-medium">Calls</th>
                        <th class="px-4 py-3 text-right font-medium">Tokens</th>
                        <th class="px-4 py-3 text-right font-medium">Cost</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="row in (period === 'month' ? month : allTime)
                            .purposes"
                        :key="row.purpose"
                    >
                        <td class="px-4 py-2.5">{{ row.purpose }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">
                            {{ row.runs }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">
                            {{ row.tokens.toLocaleString() }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">
                            {{ usd(row.cost) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section class="space-y-2">
            <h2 class="flex items-center gap-2 text-sm font-medium">
                <Sparkles class="size-4 text-muted-foreground" /> Most expensive
                calls
            </h2>
            <p
                v-if="expensive.length === 0"
                class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground"
            >
                No AI calls yet.
            </p>
            <ul v-else class="divide-y rounded-xl border text-sm">
                <li
                    v-for="run in expensive"
                    :key="run.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5"
                >
                    <span>
                        {{ run.purpose }}
                        <span class="text-muted-foreground"
                            >· {{ run.subject }}</span
                        >
                        <Badge
                            v-if="!run.succeeded"
                            variant="destructive"
                            class="ml-1"
                        >
                            failed
                        </Badge>
                    </span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ run.tokens.toLocaleString() }} tokens ·
                        <span class="font-medium text-foreground">{{
                            usd(run.cost)
                        }}</span>
                        · {{ formatInCourseTz(run.created_at) }}
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
