// laravel-pdf-templates: visual (rich text) editing of an HTML template with CKEditor 5.
// CKEditor is loaded from its CDN only when the visual editor is opened (config html.editor).
//
// CKEditor keeps a document model, not the HTML it was given, so a template is prepared before it goes in
// (protectTemplate) and put back together when it comes out (restoreTemplate):
//   - block tags ({#each x}, {else}, {/if}…) in text travel as HTML comments;
//   - block tags between table rows / cells (where HTML allows no text and no comment survives) travel as
//     data-pt-before / data-pt-after attributes of the row / cell next to them;
//   - empty inline elements (<span class="pagenum"></span>), which the editor would drop, travel as comments.
import { resolveImages, unresolveImages } from 'pdf-templates';

const TOKEN = /<!--[\s\S]*?-->|<\/?[a-zA-Z][^>]*>|[^<]+|</g;
// Also the Jinja-style block tags the engine accepts ({% for item in items %}, {% if x %}, {% endif %}…)
const BLOCK_TAG = /\{(?:#(?:each|if|unless)\s[^{}]*|\/(?:each|if|unless)|else)\}|\{%-?[^{}]*?-?%\}/g;
const PROTECTED = /<!--pt:([\s\S]*?)-->/g;
const EMPTY_INLINE = /<(span|i|b|u|em|strong|small|a)\b[^>]*>\s*<\/\1>/gi;
const TABLE_PARTS = new Set(['table', 'thead', 'tbody', 'tfoot', 'tr']);
const CELLS = new Set(['td', 'th']);

const tagOf = (token) => {
    const m = token.startsWith('<!--') ? null : /^<(\/)?([a-zA-Z][\w-]*)/.exec(token);

    return m ? { name: m[2].toLowerCase(), closing: !!m[1] } : null;
};
const withAttribute = (token, name, tags) => token.replace(/^<[a-zA-Z][\w-]*/, (open) => `${open} ${name}="${encodeURIComponent(tags.join(''))}"`);

// The block tags of a template, in order
export const tagSequence = (html) => (html.match(BLOCK_TAG) ?? []).join(' ');

// style="height:120" → height:120px: browsers (and CKEditor) drop a size without a unit, dompdf too (see HtmlRenderer)
export const SIZE_PROPERTIES = 'width|height|min-width|max-width|min-height|max-height|font-size|margin(?:-top|-right|-bottom|-left)?|padding(?:-top|-right|-bottom|-left)?|top|left|right|bottom|border-width|border-radius';
export function pxSizes(html) {
    const size = new RegExp(`(^|;)(\\s*(?:${SIZE_PROPERTIES})\\s*:\\s*)(\\d+(?:\\.\\d+)?)(?=\\s*(?:;|$))`, 'gi');

    return html.replace(/(\sstyle\s*=\s*)(["'])(.*?)\2/gis, (whole, attr, quote, css) => `${attr}${quote}${css.replace(size, (m, start, prop, num) => (Number(num) === 0 ? m : `${start}${prop}${num}px`))}${quote}`);
}

export function protectTemplate(html) {
    html = pxSizes(html);
    const tokens = html
        .replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
        .replace(EMPTY_INLINE, (element) => `<!--pt:${element}-->`)
        .match(TOKEN) ?? [];
    // A condition wrapped around one element ({#if x}<p>…</p>{/if}) travels on that element, like the ones of
    // table rows, so the visual editor can show and change it (right click → Show only if…)
    const VOID = new Set(['img', 'br', 'hr', 'input', 'meta', 'link']);
    const isText = (token) => !!token && !tagOf(token) && !token.startsWith('<!--');
    tokens.forEach((token, i) => {
        const open = isText(token) ? /^\s*\{#(if|unless)\s+[^{}]+\}\s*$/.exec(token) : null;
        const element = open && tokens[i + 1] ? tagOf(tokens[i + 1]) : null;
        if (!element || element.closing || VOID.has(element.name) || tokens[i + 1].endsWith('/>')) return;
        let depth = 0;
        for (let j = i + 1; j < tokens.length; j++) {
            const other = tagOf(tokens[j]);
            if (other?.name !== element.name || tokens[j].endsWith('/>')) continue;
            depth += other.closing ? -1 : 1;
            if (depth > 0) continue;
            const close = `{/${open[1]}}`;
            const next = tokens[j + 1] ?? '';
            if (isText(next) && next.trimStart().startsWith(close)) {
                tokens[i + 1] = withAttribute(withAttribute(tokens[i + 1], 'data-pt-after', [close]), 'data-pt-before', [token.trim()]);
                tokens[i] = '';
                tokens[j + 1] = next.trimStart().slice(close.length);
            }
            break;
        }
    });

    const stack = []; // open table parts and cells: { name, index, last: token index of the last closed row / cell }
    let pending = []; // block tags met between rows / cells, waiting for the next one
    const nearest = (names) => stack.findLast((entry) => names.includes(entry.name));

    tokens.forEach((token, i) => {
        const tag = tagOf(token);

        if (!tag) {
            if (token.startsWith('<!--')) return;
            if (stack.length && TABLE_PARTS.has(stack.at(-1).name)) {
                // Between rows / cells: only block tags can be kept, anything else would leave the table anyway
                pending.push(...(token.match(BLOCK_TAG) ?? []));
                tokens[i] = token.replace(BLOCK_TAG, '');
            } else {
                tokens[i] = token.replace(BLOCK_TAG, (blockTag) => `<!--pt:${blockTag}-->`);
            }

            return;
        }
        if (!TABLE_PARTS.has(tag.name) && !CELLS.has(tag.name)) return;

        if (!tag.closing) {
            if (pending.length && (tag.name === 'tr' || CELLS.has(tag.name))) {
                tokens[i] = withAttribute(token, 'data-pt-before', pending);
                pending = [];
            }
            stack.push({ name: tag.name, index: i, last: null });

            return;
        }

        const at = stack.findLastIndex((entry) => entry.name === tag.name);
        if (at < 0) return;
        const closed = stack[at];
        stack.length = at;

        // Tags after the last row of a table / the last cell of a row
        if (pending.length && (tag.name === 'table' || tag.name === 'tr')) {
            if (closed.last !== null) {
                tokens[closed.last] = withAttribute(tokens[closed.last], 'data-pt-after', pending);
            } else {
                tokens[i] = pending.map((blockTag) => `<!--pt:${blockTag}-->`).join('') + token;
            }
            pending = [];
        }
        const parent = CELLS.has(tag.name) ? nearest(['tr']) : (tag.name === 'tr' ? nearest(['table']) : null);
        if (parent) parent.last = closed.index;
    });

    return tokens.join('');
}

const CONTAINERS = new Set(['table', 'thead', 'tbody', 'tfoot', 'tr', 'ul', 'ol', 'div', 'blockquote', 'figure', 'section']);
const LEAVES = new Set(['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'li', 'hr', 'caption', 'figcaption']);
const UNSAFE_URL = /\s(href|src|action|formaction)\s*=\s*(["'])\s*(?:javascript|vbscript):[^"']*\2/gi;

// HTML from the editor → template: block tags and empty elements back in place, script URLs removed,
// one block per line (the editor returns a single line, unreadable in the code tab)
export function restoreTemplate(html) {
    const tokens = html
        .replace(UNSAFE_URL, '')
        // The editor fills a block left "empty" (only a protected element in it) with &nbsp;
        .replace(/(<!--pt:<[\s\S]*?-->)&nbsp;(?=<\/)/g, '$1')
        .replace(PROTECTED, '$1')
        .match(TOKEN) ?? [];
    const after = new Map(); // token index of a closing tag → block tags to write after it

    tokens.forEach((token, i) => {
        const tag = tagOf(token);
        if (!tag || tag.closing || !token.includes('data-pt-')) return;

        let before = '';
        let afterTags = '';
        tokens[i] = token.replace(/\sdata-pt-(before|after)="([^"]*)"/g, (_, where, value) => {
            if (where === 'before') before = decodeURIComponent(value);
            else afterTags = decodeURIComponent(value);

            return '';
        });
        if (afterTags) {
            let depth = 0;
            for (let j = i; j < tokens.length; j++) {
                const other = tagOf(tokens[j]);
                if (other?.name !== tag.name) continue;
                depth += other.closing ? -1 : 1;
                if (depth === 0) {
                    after.set(j, afterTags);
                    break;
                }
            }
        }
        if (before) tokens[i] = `\u0000${before}\u0000${tokens[i]}`;
    });

    if (/<(pre|textarea)\b/i.test(html)) {
        return tokens.map((token, i) => token + (after.get(i) ?? '')).join('').replaceAll('\u0000', '');
    }

    let out = '';
    let depth = 0;
    const line = (text) => {
        out += (out ? '\n' : '') + '    '.repeat(Math.max(depth, 0)) + text;
    };
    tokens.forEach((token, i) => {
        // "\0{#each x}\0<tr>": the block tags of a row / cell go on their own line above it
        const [, before, rest] = /^\u0000([\s\S]*?)\u0000([\s\S]*)$/.exec(token) ?? [null, '', token];
        if (before) line(before);

        const tag = tagOf(rest);
        const blockTagsOnly = !tag && rest.replace(BLOCK_TAG, '').trim() === '' && rest.trim() !== '';
        if (tag && CONTAINERS.has(tag.name)) {
            if (tag.closing) depth--;
            line(rest);
            if (!tag.closing) depth++;
        } else if ((tag && LEAVES.has(tag.name) && !tag.closing) || blockTagsOnly) {
            line(rest.trim());
        } else {
            out += rest;
        }
        if (after.has(i)) line(after.get(i));
    });

    return out.trim();
}

const FONT_SIZES = [7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36];

// Everything HTML + CSS can say is kept (classes, inline styles, any tag), except what can run code or load frames
const HTML_SUPPORT = {
    allow: [{ name: /.*/, attributes: true, classes: true, styles: true }],
    disallow: [
        { name: /^(script|iframe|frame|frameset|object|embed|applet|form|base|meta|link)$/ },
        {
            attributes: [
                { key: /^on(.*)/i, value: true },
                { key: /.*/, value: /(\b)(on\S+)(\s*)=|javascript:|vbscript:|(<\s*)(\/*)script/i },
                { key: /.*/, value: /data:(?!image\/(png|jpe?g|gif|webp|svg\+xml))/i },
            ],
        },
    ],
};

/**
 * A full document (APITemplate-style <html><head><style>…</style></head><body>…</body></html>): CKEditor keeps none
 * of <html>, <head>, <body> or <style>. Only what is inside <body> is edited; the rest, and the <style> / <link>
 * blocks found inside it, are kept as they are and put back around the edited content.
 * Returns { before, body, after } (before / after are '' for a template that is a plain fragment).
 */
export function splitDocument(html) {
    let before = '';
    let body = html;
    let after = '';
    const full = /^([\s\S]*?<body\b[^>]*>)([\s\S]*?)(<\/body\s*>[\s\S]*)$/i.exec(html);
    const head = full ? null : /^([\s\S]*?<\/head\s*>)([\s\S]*)$/i.exec(html);
    if (full) [, before, body, after] = full;
    else if (head) {
        [, before, body] = head;
        // </body></html> without an opening <body>
        const end = /(<\/body\s*>\s*(?:<\/html\s*>)?\s*|<\/html\s*>\s*)$/i.exec(body);
        if (end) {
            after = end[0];
            body = body.slice(0, -end[0].length);
        }
    }
    // <style> / <link> inside the body: kept in front of it
    const kept = [];
    body = body.replace(/\s*<(style)\b[^>]*>[\s\S]*?<\/style\s*>|\s*<link\b[^>]*>/gi, (block) => {
        kept.push(block);

        return '';
    });
    if (kept.length) before += kept.join('');

    return { before, body, after };
}

/**
 * Creates the editor in `element`.
 *   url, css      CKEditor 5 browser build (ckeditor5.js) and its stylesheet
 *   fonts         font families dompdf can use
 *   onChange      called after an edit made in the editor (not after setHtml)
 * Returns { setHtml(html), getHtml(), insertHtml(html), focus() }.
 */
export async function visualEditor({ element, url, css, fonts = [], language = 'en', labels = {}, onChange }) {
    if (!document.querySelector(`link[href="${css}"]`)) {
        document.head.appendChild(Object.assign(document.createElement('link'), { rel: 'stylesheet', href: css }));
    }
    const ck = await import(url);

    // Names as exported by the ckeditor5 package; a missing one (other version) is skipped instead of failing.
    // No TableProperties / TableCellProperties: they rewrite the border / width styles a template already has
    // (and drop the ones equal to CKEditor's own defaults); without them those styles pass through untouched.
    const plugins = [
        'Essentials', 'Paragraph', 'Heading', 'Bold', 'Italic', 'Underline', 'Strikethrough', 'RemoveFormat',
        'FontSize', 'FontFamily', 'FontColor', 'FontBackgroundColor', 'Alignment', 'List', 'Indent', 'IndentBlock',
        'Link', 'BlockQuote', 'HorizontalLine', 'PageBreak', 'PasteFromOffice', 'ShowBlocks',
        'Table', 'TableToolbar', 'PlainTableOutput',
        'Image', 'ImageInsertViaUrl', 'ImageResize', 'ImageStyle', 'ImageToolbar',
        'GeneralHtmlSupport', 'HtmlComment',
    ].map((name) => ck[name]).filter(Boolean);

    const editor = await ck.ClassicEditor.create(element, {
        licenseKey: 'GPL',
        language,
        plugins,
        toolbar: {
            items: [
                'undo', 'redo', '|', 'heading', '|', 'fontFamily', 'fontSize', 'fontColor', 'fontBackgroundColor', '|',
                'bold', 'italic', 'underline', 'strikethrough', 'removeFormat', '|', 'alignment', '|',
                'bulletedList', 'numberedList', 'outdent', 'indent', '|',
                'link', 'insertTable', 'insertImage', 'horizontalLine', 'pageBreak', 'blockQuote', '|', 'showBlocks',
            ],
            shouldNotGroupWhenFull: true,
        },
        // pt, like the rest of a PDF template (dompdf); any other size already in the HTML is kept
        fontSize: {
            options: FONT_SIZES.map((size) => ({
                title: `${size} pt`,
                model: `${size}pt`,
                view: { name: 'span', styles: { 'font-size': `${size}pt` } },
            })),
            supportAllValues: true,
        },
        fontFamily: { options: ['default', ...fonts], supportAllValues: true },
        link: { defaultProtocol: 'https://' },
        table: { contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells'] },
        image: {
            insert: { integrations: ['url'] },
            toolbar: ['imageStyle:inline', 'imageStyle:block', '|', 'resizeImage'],
        },
        htmlSupport: HTML_SUPPORT,
    });

    // Right-click menu: link, image, table cell / row / column, properties (style / class) of the element
    const { contextMenu } = await import('pdf-templates/visual-menu');
    contextMenu(editor, labels);

    let silent = false; // setHtml() is not an edit
    let shell = { before: '', after: '' }; // what a full document keeps around the edited body (see splitDocument)
    editor.model.document.on('change:data', () => silent || onChange?.());

    return {
        // Uploaded images (pdf-asset:…) are shown as the images themselves and stored as references again
        async setHtml(html) {
            const { before, body, after } = splitDocument(html);
            shell = { before, after };
            const data = await resolveImages(protectTemplate(body));
            silent = true;
            editor.setData(data);
            silent = false;
        },
        getHtml: () => {
            const body = restoreTemplate(unresolveImages(editor.getData()));

            return shell.before || shell.after ? `${shell.before}\n${body}\n${shell.after}` : body;
        },
        insertHtml(html) {
            editor.model.insertContent(editor.data.toModel(editor.data.processor.toView(html)));
        },
        focus: () => editor.editing.view.focus(),
    };
}
