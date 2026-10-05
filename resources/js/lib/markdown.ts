import DOMPurify from 'dompurify';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import c from 'highlight.js/lib/languages/c';
import cpp from 'highlight.js/lib/languages/cpp';
import csharp from 'highlight.js/lib/languages/csharp';
import css from 'highlight.js/lib/languages/css';
import go from 'highlight.js/lib/languages/go';
import java from 'highlight.js/lib/languages/java';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import kotlin from 'highlight.js/lib/languages/kotlin';
import php from 'highlight.js/lib/languages/php';
import python from 'highlight.js/lib/languages/python';
import ruby from 'highlight.js/lib/languages/ruby';
import rust from 'highlight.js/lib/languages/rust';
import sql from 'highlight.js/lib/languages/sql';
import swift from 'highlight.js/lib/languages/swift';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import MarkdownIt from 'markdown-it';

// Keep in step with App\Enums\CodeLanguage.
const languages = {
    bash,
    c,
    cpp,
    csharp,
    css,
    go,
    java,
    javascript,
    json,
    kotlin,
    php,
    python,
    ruby,
    rust,
    sql,
    swift,
    typescript,
    xml,
};

for (const [name, language] of Object.entries(languages)) {
    hljs.registerLanguage(name, language);
}

hljs.registerAliases(['html', 'blade', 'vue'], { languageName: 'xml' });
hljs.registerAliases(['js', 'jsx'], { languageName: 'javascript' });
hljs.registerAliases(['ts', 'tsx'], { languageName: 'typescript' });
hljs.registerAliases(['sh', 'shell', 'zsh'], { languageName: 'bash' });
hljs.registerAliases(['py'], { languageName: 'python' });
hljs.registerAliases(['c++', 'cc', 'h', 'hpp'], { languageName: 'cpp' });
hljs.registerAliases(['cs', 'c#'], { languageName: 'csharp' });
hljs.registerAliases(['golang'], { languageName: 'go' });
hljs.registerAliases(['rs'], { languageName: 'rust' });
hljs.registerAliases(['rb'], { languageName: 'ruby' });
hljs.registerAliases(['kt', 'kts'], { languageName: 'kotlin' });

function escapeHtml(code: string): string {
    return code
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

function highlight(code: string, language: string): string {
    // Bare PHP snippets (no "<?php") still deserve highlighting.
    if (language === 'php' && !code.includes('<?')) {
        return hljs.highlight(code, { language: 'php', ignoreIllegals: true })
            .value;
    }

    if (language && hljs.getLanguage(language)) {
        return hljs.highlight(code, { language, ignoreIllegals: true }).value;
    }

    return escapeHtml(code);
}

const md = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: false,
    highlight: (code, language) =>
        `<pre class="hljs"><code>${highlight(code, language.trim().toLowerCase())}</code></pre>`,
});

// DOMPurify needs a DOM; skip the hook during SSR (Markdown renders client-side).
if (DOMPurify.isSupported) {
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node.tagName === 'A') {
            node.setAttribute('target', '_blank');
            node.setAttribute('rel', 'noopener noreferrer');
        }
    });
}

/**
 * Render untrusted Markdown (question bodies, options, answers) to safe HTML.
 */
export function renderMarkdown(source: string, inline = false): string {
    const html = inline
        ? md.renderInline(source ?? '')
        : md.render(source ?? '');

    return DOMPurify.isSupported
        ? DOMPurify.sanitize(html, { ADD_ATTR: ['target'] })
        : '';
}

/**
 * Plain-text excerpt of Markdown for lists (strips fences and syntax).
 */
export function markdownExcerpt(source: string, length = 120): string {
    const text = (source ?? '')
        .replace(/```[\s\S]*?(```|$)/g, ' \u0000 ')
        .replace(/`([^`]*)`/g, '$1')
        .replace(/[#>*_~[\]()!]+/g, ' ')
        .replace(/\s+/g, ' ')
        .replaceAll('\u0000', '[code]')
        .trim();

    return text.length > length ? `${text.slice(0, length - 1)}…` : text;
}
