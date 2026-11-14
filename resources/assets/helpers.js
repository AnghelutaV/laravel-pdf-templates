// laravel-pdf-templates: shared helpers. No pdfme import, so the HTML-mode pages stay light.
import { hasPlaceholders, placeholderPaths, expressionPaths } from 'pdf-templates/placeholders';

// CSRF token from the page root (<div class="pt" data-csrf="...">), works inside any host layout
const csrf = () => document.querySelector('[data-csrf]')?.dataset.csrf ?? '';

// Types whose input is a JSON string (shown as real arrays/objects in the sample JSON)
const JSON_TYPES = ['table', 'list', 'multiVariableText'];

// pdfme inputs are string maps; arrays/objects become JSON strings. A JSON array = several documents.
export function toInputs(data) {
    const docs = Array.isArray(data) ? data : [data ?? {}];

    return docs.map((doc) => Object.fromEntries(Object.entries(doc ?? {}).map(([key, value]) => [
        key,
        value === null || value === undefined ? '' : (typeof value === 'string' ? value : JSON.stringify(value)),
    ])));
}

// Editable (non read-only) fields of a pdfme template with their current content, as friendly JSON
export function sampleFromTemplate(template) {
    const sample = {};
    for (const page of template.schemas ?? []) {
        for (const schema of page) {
            if (schema.readOnly || !schema.name || schema.name in sample) continue;

            let value = schema.content ?? '';
            if (JSON_TYPES.includes(schema.type)) {
                try {
                    value = JSON.parse(value || (schema.type === 'multiVariableText' ? '{}' : '[]'));
                } catch {
                    // keep the raw string
                }
            }
            sample[schema.name] = value;
        }
    }
    const fields = [...(template.schemas ?? []).flat(), ...(template.basePdf?.staticSchema ?? [])].filter(Boolean);

    // A text with {{ placeholders }} is not a data key itself
    fields.forEach((field) => field.type === 'text' && hasPlaceholders(field.content) && sample[field.name] === field.content && delete sample[field.name]);

    // Tables with columns bound to data: rows as objects ({"name": "Tile", "qty": "2"}) built from the example rows
    for (const field of fields) {
        if (field.type !== 'table' || !Array.isArray(field.dataColumns) || !Array.isArray(sample[field.name])) continue;
        sample[field.name] = sample[field.name].map((cells) => {
            if (!Array.isArray(cells)) return cells;
            const row = {};
            field.dataColumns.forEach((source, i) => {
                const expression = String(source ?? '').split('|')[0].trim();
                if (/^[A-Za-z_]\w*$/.test(expression) && !['rowNumber', 'rowIndex'].includes(expression)) row[expression] = cells[i] ?? '';
                else expressionPaths(expression).filter((path) => !['rowNumber', 'rowIndex'].includes(path)).forEach((path) => row[path.split('.')[0]] ??= '1');
            });

            return Object.keys(row).length ? row : cells;
        });
    }

    // Keys read by {{ placeholders }} and "Show only if" conditions: "client.name" → { client: { name: "" } }
    for (const path of placeholderPaths(template)) {
        const keys = path.split('.');
        if (keys.some((key) => /^\d+$/.test(key))) {
            sample[keys[0]] ??= [];
            continue;
        }
        let at = sample;
        keys.forEach((key, i) => {
            if (i === keys.length - 1) at[key] ??= '';
            else if (at[key] === null || typeof at[key] !== 'object') at[key] = {};
            at = at[key];
        });
    }

    return sample;
}

// Types drawn with a fixed aspect ratio: scaled uniformly so they don't stretch
const UNIFORM_TYPES = ['qrcode', 'image', 'svg', 'signature', 'checkbox', 'ellipse'];

// New blank page size with every field scaled to it: x/width by the width ratio, y/height by the height ratio,
// font sizes, cell padding and page margins by the smaller ratio. A background PDF has no known size: no scaling.
export function resizeTemplate(template, width, height) {
    const old = template.basePdf;
    if (!isBlankPage(old)) {
        return { ...template, basePdf: { width, height, padding: [0, 0, 0, 0] } };
    }

    const sx = width / old.width;
    const sy = height / old.height;
    const k = Math.min(sx, sy);
    const round = (n) => Math.round(n * 100) / 100;
    const scaleBox = (box) => (box && typeof box === 'object'
        ? Object.fromEntries(Object.entries(box).map(([side, v]) => [side, typeof v === 'number' ? round(v * k) : v]))
        : box);
    const scaleStyles = (styles) => styles && {
        ...styles,
        ...(typeof styles.fontSize === 'number' && { fontSize: round(styles.fontSize * k) }),
        ...(styles.padding && { padding: scaleBox(styles.padding) }),
    };

    const scaleField = (field) => {
        const uniform = UNIFORM_TYPES.includes(field.type);
        const next = {
            ...field,
            position: { x: round(field.position.x * sx), y: round(field.position.y * sy) },
            width: round(field.width * (uniform ? k : sx)),
            height: round(field.height * (uniform ? k : sy)),
        };
        if (typeof field.fontSize === 'number') next.fontSize = round(field.fontSize * k);
        if (field.headStyles) next.headStyles = scaleStyles(field.headStyles);
        if (field.bodyStyles) next.bodyStyles = scaleStyles(field.bodyStyles);
        if (field.padding) next.padding = scaleBox(field.padding);

        return next;
    };

    return {
        ...template,
        basePdf: {
            ...old,
            width,
            height,
            padding: (old.padding ?? [0, 0, 0, 0]).map((p) => round(p * k)),
            ...(old.staticSchema && { staticSchema: old.staticSchema.map(scaleField) }),
        },
        schemas: template.schemas.map((page) => page.map(scaleField)),
    };
}

// pdfme's Field List has no delete: a trash button on each row (shown on hover) removes that field. Rows follow the
// order of the fields on the page that is on screen. pdfme re-renders the list, so the buttons are put back by a
// MutationObserver.
export function fieldListDelete(designer, container, { label, onDelete }) {
    const trash = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

    const decorate = () => container.querySelectorAll('.pdfme-designer-list-view li').forEach((li) => {
        if (li.querySelector(':scope > .pt-field-delete')) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pt-field-delete';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.innerHTML = trash;
        // Not a drag of the row, not a selection
        button.addEventListener('pointerdown', (e) => e.stopPropagation());
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const index = [...li.parentElement.children].indexOf(li);
            const template = designer.getTemplate();
            const page = template.schemas[designer.getPageCursor()];
            if (!page?.[index]) return;
            page.splice(index, 1);
            designer.updateTemplate(template);
            onDelete?.();
        });
        li.appendChild(button);
    });

    new MutationObserver(decorate).observe(container, { childList: true, subtree: true });
    decorate();
}

// pdfme's "Edit Field" panel does not say where an image comes from: a box above the form shows the source of the
// selected image / SVG field (a library image, or one embedded in the template, with its type and size) and a
// "Replace…" button. The panel is re-rendered by pdfme, so the box is kept up to date by a MutationObserver.
export function fieldImageSource(designer, container, { t, onReplace }) {
    const describe = (field) => {
        const ref = imageRefs.get(field.content);
        if (ref) return { text: ref, library: true };

        const content = field.content ?? '';
        if (!content) return { text: t.field_source_empty };
        const data = /^data:image\/([a-z0-9.+-]+)[;,]/i.exec(content);
        const type = field.type === 'svg' ? 'SVG' : (data ? data[1].replace('+xml', '').toUpperCase() : '?');
        // A data URL is base64: 4 characters for 3 bytes
        const bytes = data ? (content.length - content.indexOf(',') - 1) * 0.75 : content.length;
        const size = bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;

        return { text: t.field_source_embedded.replace(':type', type).replace(':size', size) };
    };

    const decorate = () => {
        const detail = container.querySelector('.pdfme-designer-detail-view');
        const form = detail?.querySelector('form');
        const name = detail?.querySelector('input#name')?.value;
        const fields = designer.getTemplate().schemas[designer.getPageCursor()] ?? [];
        const field = form && fields.find((f) => f.name === name && ['image', 'svg'].includes(f.type));
        let box = detail?.querySelector('.pt-field-source:not(.pt-field-data)');
        if (!field) {
            box?.remove();
            return;
        }

        const source = describe(field);
        const key = `${field.name}|${source.text}`;
        if (box?.dataset.key === key) return;
        if (!box) {
            box = document.createElement('div');
            box.className = 'pt-field-source';
            form.parentElement.insertBefore(box, form);
        }
        box.dataset.key = key;
        box.replaceChildren();

        const label = document.createElement('span');
        label.className = 'pt-field-source-label';
        label.textContent = t.field_source;
        const value = document.createElement(source.library ? 'code' : 'span');
        value.className = 'pt-field-source-value';
        value.textContent = source.text;
        value.title = source.text;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pt-field-source-replace';
        button.textContent = t.field_source_replace;
        button.addEventListener('click', () => onReplace(field.name));
        box.append(label, value, button);
    };

    new MutationObserver(decorate).observe(container, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
    decorate();
}

// Standard header / footer: a left, a center and a right text in the top and in the bottom margin, typed in the
// editor menu. Kept as read-only text fields with fixed names (pt_header_left … pt_footer_right) in
// basePdf.staticSchema, next to whatever was drawn by hand in the header / footer layer.
const STANDARD_SLOTS = ['left', 'center', 'right'];
const STANDARD_NAME = /^pt_(header|footer)_(left|center|right)$/;
export const STANDARD_FONT_SIZE = 9;

// { header: { left, center, right, fontSize }, footer: { … } } as they are in the template
export function readStandard(template) {
    const values = {};
    for (const band of ['header', 'footer']) {
        values[band] = { left: '', center: '', right: '', fontSize: STANDARD_FONT_SIZE };
    }
    for (const field of (isBlankPage(template.basePdf) && template.basePdf.staticSchema) || []) {
        const [, band, slot] = STANDARD_NAME.exec(field.name ?? '') ?? [];
        if (!band) continue;
        values[band][slot] = field.content ?? '';
        values[band].fontSize = field.fontSize ?? STANDARD_FONT_SIZE;
    }

    return values;
}

// The template with its standard header / footer replaced by `values`; each text is centred vertically in its
// margin (a band of at least 10 mm) and spans the page between the side margins. Blank pages only.
export function writeStandard(template, values) {
    const basePdf = template.basePdf;
    if (!isBlankPage(basePdf)) return template;

    const [top = 0, right = 0, bottom = 0, left = 0] = basePdf.padding ?? [];
    const x = Math.max(left, 5);
    const width = basePdf.width - x - Math.max(right, 5);
    const round = (n) => Math.round(n * 10) / 10;
    const fields = (basePdf.staticSchema ?? []).filter((field) => !STANDARD_NAME.test(field.name ?? ''));

    for (const band of ['header', 'footer']) {
        const fontSize = Number(values[band]?.fontSize) || STANDARD_FONT_SIZE;
        const height = round(Math.max(5, fontSize * 0.3528 * 1.6));
        const middle = Math.max(band === 'header' ? top : bottom, 10) / 2;
        const y = round(band === 'header' ? middle - height / 2 : basePdf.height - middle - height / 2);

        for (const slot of STANDARD_SLOTS) {
            const content = (values[band]?.[slot] ?? '').trim();
            if (!content) continue;
            fields.push({
                name: `pt_${band}_${slot}`,
                type: 'text',
                readOnly: true,
                content,
                position: { x, y },
                width,
                height,
                fontSize,
                alignment: slot,
                verticalAlignment: 'middle',
            });
        }
    }
    const { staticSchema, ...rest } = basePdf;

    return { ...template, basePdf: fields.length ? { ...rest, staticSchema: fields } : rest };
}

// pdfme blank page ({width, height, padding}), as opposed to a background PDF (data URL)
export function isBlankPage(basePdf) {
    return !!basePdf && typeof basePdf === 'object' && !(basePdf instanceof ArrayBuffer) && !!basePdf.width && !!basePdf.height;
}

// Header / footer = pdfme's basePdf.staticSchema: fields drawn on every page, also on the pages a long table adds.
// The designer only edits `schemas`, so while the layer is edited it is shown as a one-page template of its own
// (the real pages are kept aside) and merged back by get(). Always read / write the template through get() / set().
export function staticLayer(designer) {
    let pages = null; // the real pages while the header / footer layer is on screen

    const get = () => {
        const shown = designer.getTemplate();
        if (!pages) return shown;

        // Names are unique in the whole template. pdfme replaces {currentPage}, {totalPages}, {date} and data keys
        // in read-only header / footer fields: wanted for text, not for images / SVG (their content is used as it is).
        const taken = new Set(pages.flat().map((field) => field.name));
        const layer = shown.schemas.flat().map((field) => {
            let name = field.name;
            for (let i = 2; taken.has(name); i++) name = `${field.name}_${i}`;
            taken.add(name);

            return { ...field, name, ...(!['table', 'multiVariableText'].includes(field.type) && { readOnly: field.type === 'text' }) };
        });
        const { staticSchema, ...basePdf } = shown.basePdf;

        return { ...shown, basePdf: layer.length ? { ...basePdf, staticSchema: layer } : basePdf, schemas: pages };
    };

    const set = (template) => {
        if (!pages || !isBlankPage(template.basePdf)) {
            pages = null;
            designer.updateTemplate(template);
            return;
        }
        pages = template.schemas;
        const { staticSchema = [], ...basePdf } = template.basePdf;
        designer.updateTemplate({ ...template, basePdf, schemas: [staticSchema] });
    };

    // Show the header / footer layer (true) or the pages (false). False when the page is a background PDF.
    const edit = (on) => {
        const template = get();
        if (on && !isBlankPage(template.basePdf)) return false;
        pages = on ? template.schemas : null;
        set(template);

        return true;
    };

    return { get, set, edit, editing: () => !!pages };
}

export function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

// Uploaded images (ImageLibrary): "pdf-asset:logo.png" in a template / data → data URL, for pdfme in the browser.
// The server does the same for the API and server renders. Unknown names stay as they are.
const imageCache = new Map();
const imageRefs = new Map(); // data URL → "pdf-asset:name", for unresolveImages()
export async function resolveImages(value) {
    const base = document.querySelector('[data-pt-images]')?.dataset.ptImages;
    let json = JSON.stringify(value);
    const names = [...new Set([...json.matchAll(/pdf-asset:([A-Za-z0-9][A-Za-z0-9._-]*\.(?:png|jpe?g|svg))/gi)].map((m) => m[1]))];
    if (!base || !names.length) return value;

    await Promise.all(names.map(async (name) => {
        if (!imageCache.has(name)) {
            imageCache.set(name, fetch(`${base}/${encodeURIComponent(name)}`)
                .then((response) => response.ok ? response.blob().then(readFileAsDataUrl) : null)
                .catch(() => null));
        }
        const url = await imageCache.get(name);
        if (url) {
            imageRefs.set(url, `pdf-asset:${name}`);
            json = json.split(`pdf-asset:${name}`).join(url);
        }
    }));

    return JSON.parse(json);
}

// The reverse, before saving: images that came from the library are stored as references again (small templates)
export function unresolveImages(value) {
    let json = JSON.stringify(value);
    imageRefs.forEach((ref, url) => json = json.split(url).join(ref));

    return JSON.parse(json);
}

export function notify(message, type = 'success') {
    // Inside the full screen element when there is one, otherwise the toast would be hidden behind it
    const host = document.fullscreenElement || document.body;
    let box = host.querySelector(':scope > .pt-toasts');
    if (!box) {
        box = document.createElement('div');
        box.className = 'pt-toasts';
        host.appendChild(box);
    }
    const toast = document.createElement('div');
    toast.className = 'pt-toast' + (type === 'danger' ? ' pt-toast-danger' : '');
    toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    toast.textContent = message;
    box.appendChild(toast);
    setTimeout(() => toast.remove(), type === 'danger' ? 8000 : 3000);
}

export async function saveTemplate(url, payload) {
    const response = await fetch(url, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(payload),
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(json.errors ? Object.values(json.errors).flat().join(' ') : (json.message || response.status));
    }

    return json;
}

// POST to the server renderer; returns a PDF Blob or throws with the server error
export async function renderOnServer(url, fields) {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => value !== undefined && body.append(key, value));

    const response = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body,
    });
    if (!response.ok) {
        const json = await response.json().catch(() => ({}));
        // error.code: "renderer_down" when the Node renderer is not reachable
        throw Object.assign(new Error(json.error || json.message || `HTTP ${response.status}`), { code: json.code });
    }

    return response.blob();
}

// A ⛶ button (with [data-pt-fs="off"|"on"] icons) toggling full screen on `target`; hidden where the API is missing
export function fullscreenToggle(button, target, t) {
    if (!document.fullscreenEnabled) {
        button.classList.add('pt-hidden');
        return;
    }
    button.addEventListener('click', () => document.fullscreenElement === target
        ? document.exitFullscreen()
        : target.requestFullscreen().catch((err) => notify(err.message, 'danger')));
    document.addEventListener('fullscreenchange', () => {
        const on = document.fullscreenElement === target;
        button.querySelector('[data-pt-fs="off"]').classList.toggle('pt-hidden', on);
        button.querySelector('[data-pt-fs="on"]').classList.toggle('pt-hidden', !on);
        button.dataset.ptTip = on ? t.exit_fullscreen : t.fullscreen;
        button.setAttribute('aria-label', button.dataset.ptTip);
    });
}

// Shows a PDF Blob in an iframe, freeing the previous object URL
export function showPdf(iframe, blob) {
    if (iframe.dataset.url) URL.revokeObjectURL(iframe.dataset.url);
    const url = URL.createObjectURL(blob);
    iframe.dataset.url = url;
    iframe.src = url;

    return url;
}

// Tab inserts 4 spaces in code textareas
export function codeTextarea(textarea) {
    textarea.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;
        e.preventDefault();
        const { selectionStart: start, selectionEnd: end, value } = textarea;
        textarea.value = value.slice(0, start) + '    ' + value.slice(end);
        textarea.selectionStart = textarea.selectionEnd = start + 4;
        textarea.dispatchEvent(new Event('input'));
    });
}

// <div class="pt-tabs"><button class="pt-tab" data-tab="id">…</button></div> + panels with matching ids
export function tabs(root) {
    const buttons = [...root.querySelectorAll('.pt-tab')];
    // Two tabs may share a panel (HTML code / Visual): hide all, then show the selected one
    const select = (button) => {
        buttons.forEach((b) => {
            b.setAttribute('aria-selected', b === button);
            document.getElementById(b.dataset.tab).classList.add('pt-hidden');
        });
        document.getElementById(button.dataset.tab).classList.remove('pt-hidden');
    };
    buttons.forEach((b) => b.addEventListener('click', () => select(b)));
    select(buttons[0]);
}

// Wires components/editor.blade.php: off-canvas menu, full screen, unsaved indicator, save button + Ctrl+S,
// and the "leave without saving?" dialog on [data-pt-leave] links. save() resolves true when it saved.
export function editorChrome({ save, t }) {
    const $ = (id) => document.getElementById(id);
    const drawer = $('pt-drawer');
    const shell = $('pt-shell');
    const saveBtn = $('pt-save');
    const fsButton = $('pt-fullscreen');
    const leaveDialog = $('pt-leave-dialog');
    let dirty = false;
    let leaveUrl = null;

    const setDirty = (value) => {
        dirty = value;
        saveBtn.disabled = !value;
        $('pt-dirty').classList.toggle('pt-hidden', !value);
    };
    const run = async () => {
        saveBtn.disabled = true;
        const ok = await save();
        if (!ok) saveBtn.disabled = !dirty;
        return ok;
    };
    saveBtn.addEventListener('click', run);
    onSaveShortcut(run);
    $('pt-name').addEventListener('input', (e) => $('pt-strip-title').textContent = e.target.value);

    const closeDrawer = () => drawer.open && drawer.close();
    // Menu blocks fold on a click on their title; which ones are folded is remembered in this browser
    const FOLDED = 'pt-menu-folded';
    const folded = () => {
        try {
            return new Set(JSON.parse(localStorage.getItem(FOLDED) ?? '[]'));
        } catch {
            return new Set();
        }
    };
    [...drawer.querySelectorAll('.pt-drawer-section')].forEach((section, index) => {
        const title = section.querySelector(':scope > h3');
        if (!title) return;
        const key = section.id || `section-${index}`;
        const fold = (on) => {
            section.classList.toggle('pt-collapsed', on);
            title.setAttribute('aria-expanded', !on);
        };
        const toggle = () => {
            const on = !section.classList.contains('pt-collapsed');
            fold(on);
            const keys = folded();
            if (on) keys.add(key);
            else keys.delete(key);
            try {
                localStorage.setItem(FOLDED, JSON.stringify([...keys]));
            } catch { /* private mode: not remembered */ }
        };
        title.setAttribute('role', 'button');
        title.tabIndex = 0;
        title.addEventListener('click', toggle);
        title.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            toggle();
        });
        fold(folded().has(key));
    });
    $('pt-menu').addEventListener('click', () => drawer.showModal());
    drawer.querySelector('[data-pt-close]').addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (e) => e.target === drawer && closeDrawer());
    if (drawer.hasAttribute('data-open')) drawer.showModal();
    if (drawer.dataset.status) notify(drawer.dataset.status);

    // Full screen on the whole editor (strip + workspace)
    fullscreenToggle(fsButton, shell, t);

    window.addEventListener('beforeunload', (e) => {
        if (dirty) e.preventDefault();
    });
    const leave = () => {
        dirty = false;
        location.href = leaveUrl;
    };
    document.querySelectorAll('[data-pt-leave]').forEach((link) => link.addEventListener('click', (e) => {
        if (!dirty) return;
        e.preventDefault();
        leaveUrl = link.href;
        closeDrawer();
        leaveDialog.showModal();
    }));
    leaveDialog.querySelector('[data-pt-leave-cancel]').addEventListener('click', () => leaveDialog.close());
    leaveDialog.querySelector('[data-pt-leave-discard]').addEventListener('click', leave);
    const saveAndLeave = leaveDialog.querySelector('[data-pt-leave-save]');
    saveAndLeave.addEventListener('click', async () => {
        saveAndLeave.disabled = true;
        if (await run()) leave();
        else leaveDialog.close();
        saveAndLeave.disabled = false;
    });

    // History (partials/history.blade.php): put an earlier version back, then reload the editor with it
    document.querySelectorAll('[data-pt-restore]').forEach((button) => button.addEventListener('click', async () => {
        if (!confirm(dirty ? t.version_confirm_dirty : t.version_confirm)) return;
        button.disabled = true;
        const response = await fetch(button.dataset.ptRestore, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'text/html' } });
        if (!response.ok) {
            button.disabled = false;
            notify(t.save_error.replace(':error', response.status), 'danger');
            return;
        }
        dirty = false;
        location.reload();
    }));

    return { setDirty, isDirty: () => dirty, closeDrawer };
}

// Ctrl/Cmd+S
export function onSaveShortcut(callback) {
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            callback();
        }
    });
}

// Stretches an element down to the bottom of the window, whatever layout it sits in; re-measured on resize
// and when a <details> (the settings bar) opens or closes. Below `minWidth` the element keeps its CSS height.
export function fillViewport(el, { min = 420, gap = 12, minWidth = 0 } = {}) {
    const fit = () => {
        if (window.innerWidth < minWidth) {
            el.style.height = '';
            return;
        }
        const top = el.getBoundingClientRect().top + window.scrollY;
        el.style.height = Math.max(min, window.innerHeight - top - gap) + 'px';
    };
    fit();
    window.addEventListener('resize', fit);
    document.addEventListener('toggle', fit, true);

    return fit;
}
