/**
 * Copy text, also on plain-http sites (e.g. a local .test domain), where browsers don't offer
 * navigator.clipboard. Returns false if the browser refused.
 */
export async function copyText(text: string): Promise<boolean> {
    if (window.isSecureContext && navigator.clipboard) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Fall through to the legacy method.
        }
    }

    // Inside a dialog the focus trap would steal focus from a textarea on <body>.
    const previous = document.activeElement as HTMLElement | null;
    const container = previous?.closest('[role="dialog"]') ?? document.body;
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    container.appendChild(textarea);
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, text.length);

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
        previous?.focus();
    }
}
