<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Download, FileUp } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { confirm, preview } from '@/routes/students/import';
import type { StudentImportRow } from '@/types';

const open = defineModel<boolean>('open', { required: true });
const { slug } = useCourse();

const preview_ = ref<{ token: string; rows: StudentImportRow[] } | null>(null);
const error = ref<string | null>(null);
const processing = ref(false);

watch(open, (value) => {
    if (!value) {
        preview_.value = null;
        error.value = null;
    }
});

const counts = computed(() => {
    const rows = preview_.value?.rows ?? [];

    return {
        new: rows.filter((row) => row.status === 'new').length,
        update: rows.filter((row) => row.status === 'update').length,
        unchanged: rows.filter((row) => row.status === 'unchanged').length,
        error: rows.filter((row) => row.status === 'error').length,
    };
});

const statusBadge: Record<
    StudentImportRow['status'],
    { label: string; variant: 'success' | 'info' | 'secondary' | 'destructive' }
> = {
    new: { label: 'New', variant: 'success' },
    update: { label: 'Update', variant: 'info' },
    unchanged: { label: 'Unchanged', variant: 'secondary' },
    error: { label: 'Error', variant: 'destructive' },
};

function upload(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    error.value = null;
    router.post(
        preview.url(slug.value),
        { file },
        {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onError: (errors) => (error.value = errors.file ?? null),
            onFlash: (flash) => {
                preview_.value =
                    (flash.importPreview as typeof preview_.value) ?? null;
            },
        },
    );
}

function submit() {
    router.post(
        confirm.url(slug.value),
        { token: preview_.value?.token },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Import students from CSV</DialogTitle>
                <DialogDescription>
                    Columns: <code>name</code>, <code>roll_number</code> and
                    optionally <code>email</code>. Existing roll numbers are
                    updated.
                </DialogDescription>
            </DialogHeader>

            <div v-if="!preview_" class="space-y-3">
                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center transition hover:bg-muted/50"
                >
                    <Spinner v-if="processing" class="size-6" />
                    <FileUp v-else class="size-6 text-muted-foreground" />
                    <span class="text-sm font-medium">
                        {{ processing ? 'Reading file…' : 'Choose a CSV file' }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        Up to 2,000 rows · 1 MB
                    </span>
                    <input
                        type="file"
                        accept=".csv,text/csv"
                        class="sr-only"
                        :disabled="processing"
                        @change="upload"
                    />
                </label>
                <InputError :message="error ?? undefined" />
                <a
                    href="/samples/students.csv"
                    download
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    <Download class="size-4" /> Download a sample CSV
                </a>
            </div>

            <div v-else class="space-y-4">
                <div class="flex flex-wrap gap-2 text-sm">
                    <Badge variant="success">{{ counts.new }} new</Badge>
                    <Badge variant="info">{{ counts.update }} to update</Badge>
                    <Badge variant="secondary">
                        {{ counts.unchanged }} unchanged
                    </Badge>
                    <Badge v-if="counts.error" variant="destructive">
                        {{ counts.error }} with errors (skipped)
                    </Badge>
                </div>

                <div class="max-h-80 overflow-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead
                            class="sticky top-0 bg-muted text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Line</th>
                                <th class="px-3 py-2 font-medium">Roll no.</th>
                                <th class="px-3 py-2 font-medium">Name</th>
                                <th class="px-3 py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in preview_.rows" :key="row.line">
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ row.line }}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {{ row.roll_number || '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ row.name || '—' }}
                                    <p
                                        v-if="row.error"
                                        class="text-xs text-destructive"
                                    >
                                        {{ row.error }}
                                    </p>
                                </td>
                                <td class="px-3 py-2">
                                    <Badge
                                        :variant="
                                            statusBadge[row.status].variant
                                        "
                                    >
                                        {{ statusBadge[row.status].label }}
                                    </Badge>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <DialogFooter v-if="preview_" class="gap-2">
                <Button variant="secondary" @click="preview_ = null">
                    Choose another file
                </Button>
                <Button
                    :disabled="processing || counts.new + counts.update === 0"
                    @click="submit"
                >
                    <Spinner v-if="processing" />
                    Import {{ counts.new + counts.update }}
                    {{
                        counts.new + counts.update === 1
                            ? 'student'
                            : 'students'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
