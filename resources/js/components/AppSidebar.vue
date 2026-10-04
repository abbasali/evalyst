<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ClipboardCheck,
    FolderGit2,
    Inbox,
    LayoutGrid,
    Library,
    Settings,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { start } from '@/routes/courses';
import { dashboard } from '@/routes';
import { index as assignmentsIndex } from '@/routes/assignments';
import { index as questionsIndex } from '@/routes/questions';
import { index as quizzesIndex } from '@/routes/quizzes';
import { index as reviewIndex } from '@/routes/review';
import { index as studentsIndex } from '@/routes/students';
import { edit as courseSettings } from '@/routes/teams';
import type { NavItem } from '@/types';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug).url
        : start().url,
);

const mainNavItems = computed<NavItem[]>(() => {
    const course = page.props.currentTeam?.slug;

    if (!course) {
        return [];
    }

    return [
        { title: 'Dashboard', href: dashboard(course).url, icon: LayoutGrid },
        {
            title: 'Question bank',
            href: questionsIndex(course).url,
            icon: Library,
        },
        {
            title: 'Quizzes',
            href: quizzesIndex(course).url,
            icon: ClipboardCheck,
        },
        {
            title: 'Assignments',
            href: assignmentsIndex(course).url,
            icon: FolderGit2,
        },
        { title: 'Students', href: studentsIndex(course).url, icon: Users },
        {
            title: 'Review',
            href: reviewIndex(course).url,
            icon: Inbox,
            badge: page.props.reviewCount,
        },
    ];
});

const footerNavItems = computed<NavItem[]>(() =>
    page.props.currentTeam
        ? [
              {
                  title: 'Course settings',
                  href: courseSettings(page.props.currentTeam.slug).url,
                  icon: Settings,
              },
          ]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
