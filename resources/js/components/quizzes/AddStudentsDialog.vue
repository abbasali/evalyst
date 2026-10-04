<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { toast } from 'vue-sonner';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { store as storeAssignmentParticipants } from '@/routes/assignments/participants';
import { store as storeQuizParticipants } from '@/routes/quizzes/participants';
import { index as studentsIndex } from '@/routes/students';
import type { RosterStudent } from '@/types';

const props = defineProps<{
    kind?: 'quiz' | 'assignment';
    assessmentId: number;
    roster: RosterStudent[];
    addedIds: number[];
}>();
const open = defineModel<boolean>('open', { required: true });

const { slug } = useCourse();
const search = ref('');
const selected = ref<number[]>([]);
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        search.value = '';
        selected.value = [];
    }
});

const added = computed(() => new Set(props.addedIds));
const available = computed(() =>
    props.roster.filter((student) => !added.value.has(student.id)),
);
const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term
        ? props.roster.filter(
              (student) =>
                  student.name.toLowerCase().includes(term) ||
                  student.roll_number.toLowerCase().includes(term),
          )
        : props.roster;
});
const filteredAvailable = computed(() =>
    filtered.value.filter((student) => !added.value.has(student.id)),
);
const allFilteredSelected = computed(
    () =>
        filteredAvailable.value.length > 0 &&
        filteredAvailable.value.every((student) =>
            selected.value.includes(student.id),
        ),
);

function toggle(id: number, value: boolean | 'indeterminate') {
    selected.value =
        value === true
            ? [...selected.value, id]
            : selected.value.filter((selectedId) => selectedId !== id);
}

function toggleAll(value: boolean | 'indeterminate') {
    const ids = filteredAvailable.value.map((student) => student.id);
    selected.value =
        value === true
            ? [...new Set([...selected.value, ...ids])]
            : selected.value.filter((id) => !ids.includes(id));
}

function add() {
    router.post(
        (props.kind === 'assignment'
            ? storeAssignmentParticipants
            : storeQuizParticipants
        ).url([slug.value, props.assessmentId]),
        { student_ids: selected.value },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        'Something changed. Reload and try again.',
                ),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[85svh] flex-col sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Add students</DialogTitle>
                <DialogDescription>
                    Each student gets a personal access code for this
                    {{ kind ?? 'quiz' }}.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="roster.length === 0"
                class="space-y-3 py-6 text-center text-sm text-muted-foreground"
            >
                <p>Your course roster is empty.</p>
                <Button variant="outline" as-child>
                    <Link :href="studentsIndex(slug)">
                        Add or import students
                    </Link>
                </Button>
            </div>

            <template v-else>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Search name or roll number"
                        class="pl-9"
                    />
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto rounded-lg border">
                    <label
                        v-if="filteredAvailable.length"
                        class="flex items-center gap-3 border-b bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
                    >
                        <Checkbox
                            :model-value="allFilteredSelected"
                            @update:model-value="toggleAll"
                        />
                        Select all {{ search ? 'matching' : '' }} ({{
                            filteredAvailable.length
                        }})
                    </label>
                    <ul class="divide-y">
                        <li v-for="student in filtered" :key="student.id">
                            <label
                                :class="[
                                    'flex items-center gap-3 px-3 py-2 text-sm',
                                    added.has(student.id)
                                        ? 'opacity-50'
                                        : 'cursor-pointer hover:bg-muted/30',
                                ]"
                            >
                                <Checkbox
                                    :model-value="
                                        added.has(student.id) ||
                                        selected.includes(student.id)
                                    "
                                    :disabled="added.has(student.id)"
                                    @update:model-value="
                                        (value) => toggle(student.id, value)
                                    "
                                />
                                <span class="w-24 shrink-0 font-mono text-xs">
                                    {{ student.roll_number }}
                                </span>
                                <span class="min-w-0 flex-1 truncate">
                                    {{ student.name }}
                                </span>
                                <span
                                    v-if="added.has(student.id)"
                                    class="text-xs"
                                >
                                    Added
                                </span>
                            </label>
                        </li>
                        <li
                            v-if="filtered.length === 0"
                            class="px-3 py-6 text-center text-sm text-muted-foreground"
                        >
                            No students match “{{ search }}”.
                        </li>
                    </ul>
                </div>
                <p
                    v-if="available.length === 0"
                    class="text-xs text-muted-foreground"
                >
                    Everyone on the roster is already added.
                    <Link
                        :href="studentsIndex(slug)"
                        class="underline underline-offset-2"
                    >
                        Add more students to the course
                    </Link>
                </p>
            </template>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="open = false">
                    Cancel
                </Button>
                <Button
                    v-if="roster.length"
                    :disabled="selected.length === 0 || processing"
                    @click="add"
                >
                    <Spinner v-if="processing" />
                    Add {{ selected.length || '' }}
                    {{ selected.length === 1 ? 'student' : 'students' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
