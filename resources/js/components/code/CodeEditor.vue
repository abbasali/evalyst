<script setup lang="ts">
import {
    defaultKeymap,
    history,
    historyKeymap,
    indentWithTab,
} from '@codemirror/commands';
import {
    bracketMatching,
    defaultHighlightStyle,
    indentOnInput,
    StreamLanguage,
    syntaxHighlighting,
} from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { Compartment, EditorState } from '@codemirror/state';
import {
    EditorView,
    highlightActiveLine,
    keymap,
    lineNumbers,
    placeholder as placeholderExtension,
} from '@codemirror/view';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * CodeMirror 6 editor bound with v-model. Load it with defineAsyncComponent so quizzes
 * without code questions don't download it.
 */
const props = withDefaults(
    defineProps<{
        language?: string | null;
        maxLength?: number;
        placeholder?: string;
        ariaLabel?: string;
    }>(),
    { language: null, maxLength: 20000, placeholder: '', ariaLabel: 'Code' },
);
const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ paste: [length: number] }>();

const host = ref<HTMLDivElement>();
let view: EditorView | null = null;

/**
 * Each language mode is loaded on demand, so a Python quiz never downloads the PHP parser.
 * Keep in step with App\Enums\CodeLanguage.
 */
const languageLoaders: Record<string, () => Promise<Extension>> = {
    bash: async () =>
        StreamLanguage.define(
            (await import('@codemirror/legacy-modes/mode/shell')).shell,
        ),
    blade: async () => (await import('@codemirror/lang-html')).html(),
    c: async () => (await import('@codemirror/lang-cpp')).cpp(),
    cpp: async () => (await import('@codemirror/lang-cpp')).cpp(),
    csharp: async () =>
        StreamLanguage.define(
            (await import('@codemirror/legacy-modes/mode/clike')).csharp,
        ),
    css: async () => (await import('@codemirror/lang-css')).css(),
    go: async () => (await import('@codemirror/lang-go')).go(),
    html: async () => (await import('@codemirror/lang-html')).html(),
    java: async () => (await import('@codemirror/lang-java')).java(),
    javascript: async () =>
        (await import('@codemirror/lang-javascript')).javascript(),
    json: async () =>
        (await import('@codemirror/lang-javascript')).javascript(),
    kotlin: async () =>
        StreamLanguage.define(
            (await import('@codemirror/legacy-modes/mode/clike')).kotlin,
        ),
    php: async () => (await import('@codemirror/lang-php')).php(),
    python: async () => (await import('@codemirror/lang-python')).python(),
    ruby: async () =>
        StreamLanguage.define(
            (await import('@codemirror/legacy-modes/mode/ruby')).ruby,
        ),
    rust: async () => (await import('@codemirror/lang-rust')).rust(),
    sql: async () => (await import('@codemirror/lang-sql')).sql(),
    swift: async () =>
        StreamLanguage.define(
            (await import('@codemirror/legacy-modes/mode/swift')).swift,
        ),
    typescript: async () =>
        (await import('@codemirror/lang-javascript')).javascript({
            typescript: true,
        }),
};

const languageCompartment = new Compartment();

async function loadLanguage(language: string | null): Promise<void> {
    const loader = language ? languageLoaders[language] : undefined;
    const extension = loader ? await loader() : [];

    // The prop may have changed (or the editor unmounted) while the mode was loading.
    if (view && language === props.language) {
        view.dispatch({ effects: languageCompartment.reconfigure(extension) });
    }
}

const theme = EditorView.theme({
    '&': {
        fontSize: '13px',
        backgroundColor: 'var(--background)',
        color: 'var(--foreground)',
    },
    '.cm-content': {
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
        minHeight: '14rem',
        caretColor: 'var(--foreground)',
    },
    '.cm-gutters': {
        backgroundColor: 'var(--muted)',
        color: 'var(--muted-foreground)',
        border: 'none',
    },
    '.cm-activeLine': {
        backgroundColor: 'color-mix(in oklab, var(--muted) 50%, transparent)',
    },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': { overflow: 'auto', maxHeight: '28rem' },
});

onMounted(() => {
    view = new EditorView({
        parent: host.value!,
        state: EditorState.create({
            doc: model.value,
            extensions: [
                lineNumbers(),
                history(),
                indentOnInput(),
                bracketMatching(),
                highlightActiveLine(),
                syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
                keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
                languageCompartment.of([]),
                placeholderExtension(props.placeholder),
                theme,
                EditorView.lineWrapping,
                EditorView.contentAttributes.of({
                    'aria-label': props.ariaLabel,
                }),
                EditorState.changeFilter.of(
                    (transaction) =>
                        transaction.newDoc.length <= props.maxLength,
                ),
                EditorView.domEventHandlers({
                    paste: (event) => {
                        emit(
                            'paste',
                            event.clipboardData?.getData('text').length ?? 0,
                        );
                    },
                }),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        model.value = update.state.doc.toString();
                    }
                }),
            ],
        }),
    });

    void loadLanguage(props.language);
});

watch(
    () => props.language,
    (language) => void loadLanguage(language),
);

watch(model, (value) => {
    if (view && value !== view.state.doc.toString()) {
        view.dispatch({
            changes: { from: 0, to: view.state.doc.length, insert: value },
        });
    }
});

onBeforeUnmount(() => {
    view?.destroy();
    view = null;
});
</script>

<template>
    <div
        ref="host"
        class="overflow-hidden rounded-lg border shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
    />
</template>
