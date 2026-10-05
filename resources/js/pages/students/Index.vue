<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Download,
    MoreHorizontal,
    Pencil,
    Search,
    Trash2,
    Upload,
    UserPlus,
    Users,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import ImportStudentsDialog from '@/components/students/ImportStudentsDialog.vue';
import StudentFormDialog from '@/components/students/StudentFormDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useCourse } from '@/composables/useCourse';
import { gradebook, scores } from '@/routes';
import { destroy, index } from '@/routes/students';
import type { Paginated, Student, Team } from '@/types';

const props = defineProps<{
    students: Paginated<Student>;
    filters: { search: string };
    total: number;
}>();

defineOptions({
    layout: (props: { currentTeam: Team }) => ({
        breadcrumbs: [
            { title: 'Students', href: index(props.currentTeam.slug) },
        ],
    }),
});

const { slug } = useCourse();

const search = ref(props.filters.search);
const formOpen = ref(false);
const importOpen = ref(false);
const editing = ref<Student | null>(null);
const deleting = ref<Student | null>(null);
const deleteOpen = ref(false);
const deleteProcessing = ref(false);

const applySearch = useDebounceFn((value: string) => {
    router.get(
        index.url(slug.value, { query: { search: value || undefined } }),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}, 300);

watch(search, (value) => applySearch(value));

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(student: Student) {
    editing.value = student;
    formOpen.value = true;
}

function confirmDelete(student: Student) {
    deleting.value = student;
    deleteOpen.value = true;
}

function remove() {
    if (!deleting.value) {
        return;
    }

    router.visit(destroy([slug.value, deleting.value.id]), {
        preserveScroll: true,
        onStart: () => (deleteProcessing.value = true),
        onFinish: () => (deleteProcessing.value = false),
        onSuccess: () => (deleteOpen.value = false),
    });
}
</script>

<template>
    <Head title="Students" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Students"
            description="Your course roster. Students join assessments with access codes — no accounts needed."
        >
            <template #actions>
                <DropdownMenu v-if="total > 0">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline"><Download /> Export</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <DropdownMenuItem as-child>
                            <a
                                :href="scores.url(slug)"
                                class="flex-col items-start gap-0.5"
                            >
                                <span>All scores</span>
                                <span class="text-xs text-muted-foreground">
                                    Every finished grade, released or not
                                </span>
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <a
                                :href="gradebook.url(slug)"
                                class="flex-col items-start gap-0.5"
                            >
                                <span>Gradebook</span>
                                <span class="text-xs text-muted-foreground">
                                    Only scores students can see
                                </span>
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button variant="outline" @click="importOpen = true">
                    <Upload /> Import CSV
                </Button>
                <Button @click="openCreate"><UserPlus /> Add student</Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="total === 0"
            :icon="Users"
            title="No students yet"
            description="Add students one by one or import your class list from a CSV file."
        >
            <Button variant="outline" @click="importOpen = true">
                <Upload /> Import CSV
            </Button>
            <Button @click="openCreate"><UserPlus /> Add student</Button>
        </EmptyState>

        <template v-else>
            <div class="relative max-w-sm">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search name, roll number or email"
                    class="pl-9"
                />
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-3 font-medium">Roll no.</th>
                            <th class="px-4 py-3 font-medium">Name</th>
                            <th
                                class="hidden px-4 py-3 font-medium sm:table-cell"
                            >
                                Email
                            </th>
                            <th class="px-4 py-3 text-right font-medium">
                                Total score
                            </th>
                            <th class="w-12 px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="student in students.data"
                            :key="student.id"
                            class="transition-colors hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-mono text-xs">
                                {{ student.roll_number }}
                            </td>
                            <td class="px-4 py-3 font-medium">
                                {{ student.name }}
                            </td>
                            <td
                                class="hidden px-4 py-3 text-muted-foreground sm:table-cell"
                            >
                                {{ student.email ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span
                                    v-if="student.total_score === null"
                                    class="text-muted-foreground"
                                    >—</span
                                >
                                <template v-else>{{
                                    student.total_score
                                }}</template>
                            </td>
                            <td class="px-2 py-2 text-right">
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            :aria-label="`Actions for ${student.name}`"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            @click="openEdit(student)"
                                        >
                                            <Pencil /> Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            variant="destructive"
                                            @click="confirmDelete(student)"
                                        >
                                            <Trash2 /> Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                        <tr v-if="students.data.length === 0">
                            <td
                                colspan="5"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                {{
                                    filters.search
                                        ? `No students match “${filters.search}”.`
                                        : 'No students on this page.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="students" noun="students" />
        </template>
    </div>

    <StudentFormDialog v-model:open="formOpen" :student="editing" />
    <ImportStudentsDialog v-model:open="importOpen" />
    <ConfirmDialog
        v-model:open="deleteOpen"
        title="Delete student?"
        :description="
            deleting
                ? `${deleting.name} (${deleting.roll_number}) will be removed from this course.`
                : ''
        "
        confirm-label="Delete"
        destructive
        :processing="deleteProcessing"
        @confirm="remove"
    />
</template>
