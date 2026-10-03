import DOMPurify from 'dompurify';
import hljs from 'highlight.js/lib/core';
import bash from 'highlight.js/lib/languages/bash';
import css from 'highlight.js/lib/languages/css';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import sql from 'highlight.js/lib/languages/sql';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import MarkdownIt from 'markdown-it';

hljs.registerLanguage('php', php);
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('typescript', typescript);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('css', css);
hljs.registerLanguage('json', json);
hljs.registerAliases(['html', 'blade', 'vue'], { languageName: 'xml' });
hljs.registerAliases(['js'], { languageName: 'javascript' });
hljs.registerAliases(['ts'], { languageName: 'typescript' });
hljs.registerAliases(['sh', 'shell', 'zsh'], { languageName: 'bash' });

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

DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
});

/**
 * Render untrusted Markdown (question bodies, options, answers) to safe HTML.
 */
export function renderMarkdown(source: string, inline = false): string {
    const html = inline
        ? md.renderInline(source ?? '')
        : md.render(source ?? '');

    return DOMPurify.sanitize(html, { ADD_ATTR: ['target'] });
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
