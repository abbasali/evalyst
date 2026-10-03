<script setup lang="ts">
import { ref, watch } from 'vue';
import MarkdownEditor from '@/components/markdown/MarkdownEditor.vue';
import OptionsEditor from '@/components/questions/OptionsEditor.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { GeneratedDraft, Option } from '@/types';

const props = defineProps<{
    draft: GeneratedDraft | null;
    scoringPolicies: Option[];
    codeLanguages: Option[];
    difficulties: Option[];
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ save: [draft: GeneratedDraft] }>();

const local = ref<GeneratedDraft | null>(null);

watch(
    () => [open.value, props.draft] as const,
    ([isOpen, draft]) => {
        if (isOpen && draft) {
            local.value = JSON.parse(JSON.stringify(draft));
        }
    },
    { immediate: true },
);

function save() {
    if (local.value) {
        emit('save', local.value);
        open.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Edit draft</DialogTitle>
                <DialogDescription>
                    Changes are kept on this page until you add the draft to the
                    bank.
                </DialogDescription>
            </DialogHeader>

            <div v-if="local" class="space-y-5">
                <div class="space-y-2">
                    <Label>Question</Label>
                    <MarkdownEditor v-model="local.body" :rows="5" />
                </div>

                <div v-if="local.options.length" class="space-y-2">
                    <Label>Options</Label>
                    <OptionsEditor
                        v-model="local.options"
                        :multiple="local.type === 'multiple_choice'"
                        :errors="{}"
                    />
                </div>

                <template v-else>
                    <div v-if="local.type === 'open_code'" class="space-y-2">
                        <Label>Code language</Label>
                        <NativeSelect
                            v-model="local.code_language"
                            class="max-w-xs"
                        >
                            <option
                                v-for="l in codeLanguages"
                                :key="l.value"
                                :value="l.value"
                            >
                                {{ l.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="space-y-2">
                        <Label>Model answer</Label>
                        <MarkdownEditor
                            :model-value="local.model_answer ?? ''"
                            :rows="4"
                            @update:model-value="
                                (v) => local && (local.model_answer = v)
                            "
                        />
                    </div>
                    <div class="space-y-2">
                        <Label>Rubric</Label>
                        <MarkdownEditor
                            :model-value="local.rubric ?? ''"
                            :rows="3"
                            @update:model-value="
                                (v) => local && (local.rubric = v)
                            "
                        />
                    </div>
                </template>

                <div class="space-y-2">
                    <Label>Explanation</Label>
                    <MarkdownEditor
                        :model-value="local.explanation ?? ''"
                        :rows="3"
                        @update:model-value="
                            (v) => local && (local.explanation = v)
                        "
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="space-y-2">
                        <Label>Marks</Label>
                        <Input
                            v-model.number="local.default_marks"
                            type="number"
                            min="0.5"
                            max="100"
                            step="0.5"
                        />
                    </div>
                    <div class="space-y-2">
                        <Label>Difficulty</Label>
                        <NativeSelect v-model="local.difficulty">
                            <option :value="null">—</option>
                            <option
                                v-for="d in difficulties"
                                :key="d.value"
                                :value="d.value"
                            >
                                {{ d.label }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div
                        v-if="local.type === 'multiple_choice'"
                        class="space-y-2"
                    >
                        <Label>Scoring</Label>
                        <NativeSelect v-model="local.scoring_policy">
                            <option
                                v-for="p in scoringPolicies"
                                :key="p.value"
                                :value="p.value"
                            >
                                {{ p.label }}
                            </option>
                        </NativeSelect>
                    </div>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="open = false"
                    >Cancel</Button
                >
                <Button @click="save">Save draft</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
