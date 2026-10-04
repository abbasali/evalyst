<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, KeyRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import StudentLayout from '@/layouts/StudentLayout.vue';
import { store } from '@/routes/student/join';

type SharedQuiz = { code: string; title: string; course: string };

const page = usePage();
const shared = ref<SharedQuiz | null>(null);

watch(
    () => page.flash?.sharedQuiz as SharedQuiz | undefined,
    (value) => {
        if (value) {
            shared.value = value;
        }
    },
    { immediate: true },
);

const form = useForm({ code: '', name: '', roll_number: '' });

/** Show the code the way it's printed: letters and digits only, upper case. */
function onCodeInput(event: Event) {
    form.code = (event.target as HTMLInputElement).value
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, 8);
}

const step = computed(() => (shared.value ? 'details' : 'code'));

function submit() {
    form.transform((data) => ({
        ...data,
        code: shared.value?.code ?? data.code,
    })).post(store.url(), {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.code) {
                shared.value = null;
            }
        },
    });
}

function back() {
    shared.value = null;
    form.clearErrors();
}
</script>

<template>
    <Head title="Join" />

    <StudentLayout>
        <div class="mx-auto mt-6 max-w-sm space-y-6 sm:mt-14">
            <div class="space-y-2 text-center">
                <div
                    class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary/10"
                >
                    <KeyRound class="size-5 text-primary" />
                </div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{
                        step === 'code' ? 'Join with your code' : shared!.title
                    }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        step === 'code'
                            ? 'Enter the code your instructor gave you.'
                            : `${shared!.course} · Enter your details to continue.`
                    }}
                </p>
            </div>

            <form
                class="space-y-4 rounded-xl border bg-background p-5 shadow-xs"
                @submit.prevent="submit"
            >
                <template v-if="step === 'code'">
                    <div class="space-y-2">
                        <Label for="code">Access code</Label>
                        <Input
                            id="code"
                            v-focus
                            :model-value="form.code"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            inputmode="text"
                            placeholder="K7MX4T"
                            class="h-12 text-center font-mono text-xl tracking-[0.3em] placeholder:text-muted-foreground/40"
                            :aria-invalid="!!form.errors.code"
                            @input="onCodeInput"
                        />
                        <InputError :message="form.errors.code" />
                    </div>
                </template>

                <template v-else>
                    <div class="space-y-2">
                        <Label for="name">Full name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            v-focus
                            maxlength="100"
                            autocomplete="name"
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="roll_number">Roll number</Label>
                        <Input
                            id="roll_number"
                            v-model="form.roll_number"
                            maxlength="50"
                            autocomplete="off"
                            required
                        />
                        <p class="text-xs text-muted-foreground">
                            Use the same roll number every time. It links your
                            results to you.
                        </p>
                        <InputError :message="form.errors.roll_number" />
                    </div>
                </template>

                <Button
                    type="submit"
                    size="lg"
                    class="w-full"
                    :disabled="
                        form.processing ||
                        (step === 'code' && form.code.length < 6)
                    "
                >
                    <Spinner v-if="form.processing" />
                    Continue
                </Button>
                <Button
                    v-if="step === 'details'"
                    type="button"
                    variant="ghost"
                    class="w-full"
                    @click="back"
                >
                    <ArrowLeft /> Use a different code
                </Button>
            </form>
        </div>
    </StudentLayout>
</template>
