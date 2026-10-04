<script setup lang="ts">
import {
    defaultKeymap,
    history,
    historyKeymap,
    indentWithTab,
} from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { php } from '@codemirror/lang-php';
import { sql } from '@codemirror/lang-sql';
import {
    bracketMatching,
    defaultHighlightStyle,
    indentOnInput,
    syntaxHighlighting,
} from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { EditorState } from '@codemirror/state';
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

function languageExtension(language: string | null): Extension {
    switch (language) {
        case 'php':
            return php();
        case 'blade':
        case 'html':
            return html();
        case 'javascript':
        case 'json':
            return javascript();
        case 'typescript':
            return javascript({ typescript: true });
        case 'sql':
            return sql();
        case 'css':
            return css();
        default:
            return [];
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
                languageExtension(props.language),
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
});

watch(model, (value) => {
    if (view && value !== view.state.doc.toString()) {
        view.dispatch({
            changes: { from: 0, to: view.state.doc.length, insert: value },
        });
    }
});

onBeforeUnmount(() => view?.destroy());
</script>

<template>
    <div
        ref="host"
        class="overflow-hidden rounded-lg border shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
    />
</template>
