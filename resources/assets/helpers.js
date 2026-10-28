// laravel-pdf-templates: shared helpers. No pdfme import, so the HTML-mode pages stay light.

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

    return sample;
}

// Types drawn with a fixed aspect ratio: scaled uniformly so they don't stretch
const UNIFORM_TYPES = ['qrcode', 'image', 'svg', 'signature', 'checkbox', 'ellipse'];

// New blank page size with every field scaled to it: x/width by the width ratio, y/height by the height ratio,
// font sizes, cell padding and page margins by the smaller ratio. A background PDF has no known size: no scaling.
export function resizeTemplate(template, width, height) {
    const old = template.basePdf;
    const blank = old && typeof old === 'object' && !(old instanceof ArrayBuffer) && old.width && old.height;
    if (!blank) {
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
        basePdf: { ...old, width, height, padding: (old.padding ?? [0, 0, 0, 0]).map((p) => round(p * k)) },
        schemas: template.schemas.map((page) => page.map(scaleField)),
    };
}

export function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

export function notify(message, type = 'success') {
    let box = document.querySelector('.pt-toasts');
    if (!box) {
        box = document.createElement('div');
        box.className = 'pt-toasts';
        document.body.appendChild(box);
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
        throw new Error(json.error || json.message || `HTTP ${response.status}`);
    }

    return response.blob();
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
    const select = (button) => buttons.forEach((b) => {
        const on = b === button;
        b.setAttribute('aria-selected', on);
        document.getElementById(b.dataset.tab).classList.toggle('pt-hidden', !on);
    });
    buttons.forEach((b) => b.addEventListener('click', () => select(b)));
    select(buttons[0]);
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
