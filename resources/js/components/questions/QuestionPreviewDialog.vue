<script setup lang="ts">
import { Link, useHttp } from '@inertiajs/vue3';
import { Eye, EyeOff, Lock, Pencil } from '@lucide/vue';
import { ref, watch } from 'vue';
import Markdown from '@/components/markdown/Markdown.vue';
import QuestionTypeBadge from '@/components/questions/QuestionTypeBadge.vue';
import QuestionView from '@/components/questions/QuestionView.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useCourse } from '@/composables/useCourse';
import { edit, show } from '@/routes/questions';
import type { Question } from '@/types';

const props = defineProps<{ questionId: number | null }>();
const open = defineModel<boolean>('open', { required: true });

const { slug } = useCourse();
const http = useHttp<Record<string, never>, Question>();
const question = ref<Question | null>(null);
const showAnswers = ref(false);
const failed = ref(false);

watch(
    () => [open.value, props.questionId] as const,
    async ([isOpen, id]) => {
        if (!isOpen || !id) {
            return;
        }

        question.value = null;
        failed.value = false;
        showAnswers.value = false;

        try {
            const loaded = await http.get(show.url([slug.value, id]));

            // Ignore a slow response for a question that's no longer shown.
            if (props.questionId === id) {
                question.value = loaded;
            }
        } catch {
            failed.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    Preview
                    <QuestionTypeBadge
                        v-if="question"
                        :type="question.type"
                        :label="question.type_label"
                    />
                    <Lock
                        v-if="question?.locked"
                        class="size-4 text-muted-foreground"
                    />
                </DialogTitle>
                <DialogDescription>
                    This is how students will see the question.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="failed"
                class="py-10 text-center text-sm text-muted-foreground"
            >
                This question couldn't be loaded.
            </p>
            <div v-else-if="!question" class="flex justify-center py-10">
                <Spinner class="size-6" />
            </div>

            <template v-else>
                <div class="rounded-xl border p-5">
                    <QuestionView
                        :type="question.type"
                        :body="question.body"
                        :options="question.options"
                        :code-language="question.code_language"
                        :show-answers="showAnswers"
                    />
                </div>

                <div
                    v-if="showAnswers"
                    class="space-y-4 rounded-xl bg-muted/40 p-4 text-sm"
                >
                    <div v-if="question.model_answer">
                        <p class="mb-1 font-medium">Model answer</p>
                        <Markdown :source="question.model_answer" />
                    </div>
                    <div v-if="question.rubric">
                        <p class="mb-1 font-medium">Rubric</p>
                        <Markdown :source="question.rubric" />
                    </div>
                    <div v-if="question.explanation">
                        <p class="mb-1 font-medium">Explanation</p>
                        <Markdown :source="question.explanation" />
                    </div>
                    <p
                        v-if="
                            !question.model_answer &&
                            !question.rubric &&
                            !question.explanation
                        "
                        class="text-muted-foreground"
                    >
                        No model answer, rubric or explanation.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <Badge variant="outline">
                            {{ question.default_marks }}
                            {{
                                question.default_marks === 1 ? 'mark' : 'marks'
                            }}
                        </Badge>
                        <Badge v-if="question.difficulty" variant="outline">
                            {{ question.difficulty }}
                        </Badge>
                        <Badge
                            v-if="question.needs_verification"
                            variant="warning"
                        >
                            Needs verification
                        </Badge>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="showAnswers = !showAnswers"
                        >
                            <component :is="showAnswers ? EyeOff : Eye" />
                            {{ showAnswers ? 'Hide answers' : 'Show answers' }}
                        </Button>
                        <Button v-if="!question.deleted" size="sm" as-child>
                            <Link :href="edit([slug, question.id])">
                                <Pencil /> Edit
                            </Link>
                        </Button>
                    </div>
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
