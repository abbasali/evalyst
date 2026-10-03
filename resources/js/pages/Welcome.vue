<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const page = usePage();
const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);
</script>

<template>
    <Head title="Welcome" />
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background p-6"
    >
        <div
            class="flex w-full max-w-md flex-col items-center gap-8 text-center"
        >
            <div
                class="flex size-14 items-center justify-center rounded-xl bg-primary text-primary-foreground"
            >
                <AppLogoIcon class="size-8 fill-current" />
            </div>

            <div class="space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight">
                    {{ page.props.name }}
                </h1>
                <p class="text-muted-foreground">
                    Quizzes and project assignments for programming courses,
                    graded instantly or with AI.
                </p>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <Button v-if="page.props.auth.user" as-child size="lg">
                    <Link :href="dashboardUrl">Go to dashboard</Link>
                </Button>
                <Button v-else as-child size="lg">
                    <Link :href="login()">Instructor log in</Link>
                </Button>
            </div>
        </div>
    </div>
</template>
