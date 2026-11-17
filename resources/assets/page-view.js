// laravel-pdf-templates: visual editing of an HTML template printed by the Chrome engine.
// CKEditor keeps a document model and rewrites what it does not know (flexbox, grid, position: absolute, a fixed
// sheet), so for Chrome templates the page is shown by the browser itself, as Chrome prints it, in an iframe the
// size of the paper, and its text is edited in place (contenteditable). The template's own CSS, <head> and layout
// are untouched; loops / conditions travel the same way as in the CKEditor view (protectTemplate / restoreTemplate).
import { resolveImages, unresolveImages } from 'pdf-templates';
import { protectTemplate, restoreTemplate, splitDocument } from 'pdf-templates/visual';

// Paper sizes in mm (portrait)
const PAPERS = { a3: [297, 420], a4: [210, 297], a5: [148, 210], letter: [215.9, 279.4], legal: [215.9, 355.6] };
const MM = 96 / 25.4;
const UNSAFE = /\s(on[a-z]+)\s*=\s*(["'])[\s\S]*?\2|\s(href|src|action|formaction)\s*=\s*(["'])\s*(?:javascript|vbscript):[^"']*\4/gi;

const BUTTONS = [
    ['undo', '↶', 'undo'], ['redo', '↷', 'redo'], null,
    ['bold', 'B', 'bold'], ['italic', 'I', 'italic'], ['underline', 'U', 'underline'], ['strikeThrough', 'S', 'strike'], null,
    ['justifyLeft', '⇤', 'align_left'], ['justifyCenter', '↔', 'align_center'], ['justifyRight', '⇥', 'align_right'], null,
    ['removeFormat', '⌫', 'remove_format'],
];

/**
 * Creates the page view in `element`.
 *   page()      { paper, landscape, margins: [top, right, bottom, left] mm | null }, read on every setHtml
 *   extraCss()  CSS added to the page (test-data images for <img src="{{ key }}">)
 *   onChange    called after an edit made in the page (not after setHtml)
 * Returns { setHtml(html, css), getHtml(), insertHtml(html), focus(), refresh() }, like visualEditor().
 */
export function pageView({ element, page, extraCss = () => '', labels = {}, onChange }) {
    element.replaceChildren();
    element.classList.add('pt-page-view');
    const toolbar = element.appendChild(document.createElement('div'));
    toolbar.className = 'pt-page-view-toolbar';
    const desk = element.appendChild(document.createElement('div'));
    desk.className = 'pt-page-view-desk';
    const frame = desk.appendChild(document.createElement('iframe'));
    frame.title = labels.visual_page ?? 'Page';

    let shell = { before: '', after: '' };
    let source = { html: '', css: '' };
    let width = 0;
    const doc = () => frame.contentDocument;

    BUTTONS.forEach((button) => {
        if (!button) {
            toolbar.appendChild(document.createElement('span')).className = 'pt-page-view-sep';
            return;
        }
        const [command, text, label] = button;
        const btn = toolbar.appendChild(document.createElement('button'));
        btn.type = 'button';
        btn.className = 'pt-btn pt-btn-ghost pt-btn-sm';
        btn.textContent = text;
        btn.title = labels[`visual_${label}`] ?? label;
        btn.addEventListener('mousedown', (e) => e.preventDefault()); // keep the selection in the page
        btn.addEventListener('click', () => {
            doc().execCommand(command);
            changed();
        });
    });

    function fit() {
        if (!width) return;
        const zoom = Math.min(1, (desk.clientWidth - 24) / width);
        frame.style.width = `${width}px`;
        frame.style.zoom = String(zoom > 0 ? zoom : 1);
        const root = doc()?.documentElement;
        if (root) frame.style.height = `${Math.max(root.scrollHeight, root.offsetHeight)}px`;
    }
    new ResizeObserver(fit).observe(desk);

    function changed() {
        fit();
        source.html = view.getHtml();
        onChange?.();
    }

    async function build() {
        const { paper = 'a4', landscape = false, margins = null } = page();
        const [w, h] = PAPERS[String(paper).toLowerCase()] ?? PAPERS.a4;
        const [pw, ph] = landscape ? [h, w] : [w, h];
        // Margins of the menu, else the template's own @page margin, else the default one (document.blade.php)
        const atPage = /@page\b[^{]*\{[^}]*?\bmargin\s*:\s*([^;}]+)/i.exec(`${shell.before}\n${source.css}`)?.[1]?.trim();
        const padding = margins ? margins.map((m) => `${Number(m) || 0}mm`).join(' ') : (atPage ?? '15mm');
        width = pw * MM;

        const body = (await resolveImages(protectTemplate(splitDocument(source.html).body))).replace(UNSAFE, '');
        const base = `<style>
html { box-sizing: border-box; width: ${pw}mm; min-height: ${ph}mm; padding: ${padding}; background: #fff; color: #000; }
body { font-family: "${labels.default_font ?? 'DejaVu Sans'}", sans-serif; font-size: 10pt; }
body:focus { outline: none; }
[data-pt-before], [data-pt-after] { outline: 1px dashed #f59e0b; outline-offset: -1px; }
</style>`;
        const head = shell.before ? shell.before.replace(UNSAFE, '').replace(/<script\b[\s\S]*?<\/script\s*>/gi, '') : '';
        const extra = `<style>${source.css}\n${extraCss()}</style>`;
        // The base styles go first so the template's CSS wins, as in the PDF
        const html = /<head\b[^>]*>/i.test(head)
            ? `${head.replace(/<head\b[^>]*>/i, (tag) => `${tag}${base}${extra}`)}${/<body\b/i.test(head) ? '' : '<body>'}${body}</body></html>`
            : `<!DOCTYPE html><html><head><meta charset="utf-8">${base}${head}${extra}</head><body>${body}</body></html>`;

        await new Promise((resolve) => {
            frame.onload = resolve;
            frame.srcdoc = html;
        });
        const d = doc();
        d.body.contentEditable = 'true';
        d.body.spellcheck = false;
        try {
            d.execCommand('styleWithCSS', false, true);
        } catch {
            // older browsers
        }
        d.addEventListener('input', changed);
        d.querySelectorAll('img').forEach((img) => img.addEventListener('load', fit));
        new ResizeObserver(fit).observe(d.documentElement);
        fit();
    }

    const view = {
        async setHtml(html, css = '') {
            const { before, after } = splitDocument(html);
            shell = { before, after };
            source = { html, css };
            await build();
        },
        // Re-shows the page (paper, margins, CSS or test data changed); keeps the HTML as it is now
        async refresh(css = source.css) {
            source.css = css;
            await build();
        },
        getHtml() {
            const d = doc();
            if (!d?.body?.isContentEditable) return source.html;
            const clone = d.body.cloneNode(true);
            clone.removeAttribute('contenteditable');
            clone.removeAttribute('spellcheck');
            const body = restoreTemplate(unresolveImages(clone.innerHTML));

            return shell.before || shell.after ? `${shell.before}\n${body}\n${shell.after}` : body;
        },
        insertHtml(html) {
            doc().body.focus();
            doc().execCommand('insertHTML', false, html);
            changed();
        },
        focus: () => doc()?.body.focus(),
    };

    return view;
}
