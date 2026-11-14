// laravel-pdf-templates: HTML editor menu tools — page setup (margins, header / footer, word break),
// font library (upload / Google Fonts) and the import of external images into the image library.
import { notify } from 'pdf-templates';

const csrf = () => document.querySelector('[data-csrf]')?.dataset.csrf ?? '';

async function request(url, options = {}) {
    const json = options.json !== undefined;
    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', ...(json && { 'Content-Type': 'application/json' }) },
        body: json ? JSON.stringify(options.json) : options.body,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? response.statusText);

    return data;
}

/**
 * partials/page-setup.blade.php. `initial` = the template's settings; onChange() after every edit.
 * Returns { value() } → the settings to save / preview (null when nothing is set).
 */
export function pageSetup({ initial, onChange }) {
    const fields = [...document.querySelectorAll('[data-pt-setup]')];
    const get = (object, path) => path.split('.').reduce((value, key) => value?.[key], object);

    fields.forEach((field) => {
        const value = get(initial ?? {}, field.dataset.ptSetup);
        if (value !== undefined && value !== null && value !== '') field.value = value;
        field.addEventListener(field.tagName === 'SELECT' ? 'change' : 'input', () => onChange());
    });
    // A custom header / footer that is set is shown open
    document.querySelectorAll('.pt-band-custom').forEach((details) => {
        if (details.querySelector('textarea')?.value) details.open = true;
    });

    // {currentPage} … chips: inserted at the caret of the header / footer text used last
    const texts = fields.filter((field) => 'ptSetupText' in field.dataset);
    let last = texts.find((field) => field.dataset.ptSetup === 'footer.right');
    texts.forEach((field) => field.addEventListener('focus', () => last = field));
    document.querySelectorAll('[data-pt-setup-var]').forEach((chip) => chip.addEventListener('click', () => {
        const at = last.selectionStart ?? last.value.length;
        last.setRangeText(chip.dataset.ptSetupVar, at, last.selectionEnd ?? at, 'end');
        last.focus();
        onChange();
    }));

    return {
        value() {
            const read = (path) => fields.find((field) => field.dataset.ptSetup === path)?.value.trim() ?? '';
            const settings = {};

            const margins = [0, 1, 2, 3].map((i) => read(`margins.${i}`));
            if (margins.some((m) => m !== '')) settings.margins = margins.map((m) => (m === '' ? null : Number(m)));

            for (const band of ['header', 'footer']) {
                const texts = Object.fromEntries(['left', 'center', 'right', 'html'].map((slot) => [slot, read(`${band}.${slot}`)]));
                if (Object.values(texts).some((text) => text !== '')) {
                    settings[band] = { ...texts, font_size: Number(read(`${band}.font_size`)) || 9 };
                }
            }
            if (read('word_break')) settings.word_break = read('word_break');
            if (read('engine')) settings.engine = read('engine');

            return Object.keys(settings).length ? settings : null;
        },
    };
}

/** Fonts section of partials/page-setup.blade.php; onChange(families) after the list changed */
export function fontLibrary({ t, onChange }) {
    const section = document.getElementById('pt-fonts');
    const list = document.getElementById('pt-fonts-list');
    const url = section.dataset.url;
    const VARIANT = { 'normal,normal': 'Regular', 'bold,normal': 'Bold', 'normal,italic': 'Italic', 'bold,italic': 'Bold italic' };

    function render(state, changed = false) {
        const families = new Map();
        state.fonts.forEach((font) => {
            families.set(font.family, [...(families.get(font.family) ?? []), VARIANT[`${font.weight},${font.style}`] ?? font.weight]);
        });
        list.replaceChildren();
        if (!families.size && !state.config.length) {
            const empty = document.createElement('li');
            empty.className = 'pt-muted';
            empty.textContent = t.fonts_empty;
            list.append(empty);
        }
        state.config.forEach((family) => families.has(family) || families.set(family, null));
        families.forEach((variants, family) => {
            const row = document.createElement('li');
            const text = document.createElement('span');
            const name = document.createElement('strong');
            name.textContent = family;
            const info = document.createElement('span');
            info.className = 'pt-muted';
            info.textContent = variants ? variants.join(', ') : 'config';
            text.append(name, info);
            row.append(text);
            if (variants) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'pt-btn pt-btn-sm';
                remove.textContent = t.delete;
                remove.addEventListener('click', async () => {
                    if (!confirm(t.fonts_delete_confirm.replace(':name', family))) return;
                    try {
                        render(await request(url, { method: 'DELETE', json: { family } }), true);
                    } catch (e) {
                        notify(e.message, 'danger');
                    }
                });
                row.append(remove);
            }
            list.append(row);
        });
        if (changed) onChange?.(state.families);
    }

    const busy = async (button, work) => {
        button.disabled = true;
        try {
            await work();
        } catch (e) {
            notify(e.message, 'danger');
        } finally {
            button.disabled = false;
        }
    };

    const googleInput = document.getElementById('pt-font-google');
    const googleButton = document.getElementById('pt-font-google-add');
    const addGoogle = () => googleInput.value.trim() && busy(googleButton, async () => {
        const state = await request(section.dataset.google, { method: 'POST', json: { family: googleInput.value.trim() } });
        notify(t.fonts_added.replace(':name', googleInput.value.trim()).replace(':count', state.added));
        googleInput.value = '';
        render(state, true);
    });
    googleButton.addEventListener('click', addGoogle);
    googleInput.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault(); // not a submit of the editor form
        addGoogle();
    });

    const file = document.getElementById('pt-font-file');
    file.addEventListener('change', async () => {
        const family = document.getElementById('pt-font-family').value.trim()
            || file.files[0]?.name.replace(/\.[^.]+$/, '').replace(/[-_](regular|bold|italic|bolditalic)$/i, '').replace(/[^A-Za-z0-9 _-]+/g, ' ').trim();
        if (!file.files[0] || !family) return;
        const [weight, style] = document.getElementById('pt-font-variant').value.split(',');
        const body = new FormData();
        body.append('file', file.files[0]);
        body.append('family', family);
        body.append('weight', weight);
        body.append('style', style);
        try {
            render(await request(url, { method: 'POST', body }), true);
            notify(t.fonts_added.replace(':name', family).replace(':count', 1));
        } catch (e) {
            notify(e.message, 'danger');
        }
        file.value = '';
    });

    request(url).then((state) => render(state)).catch((e) => notify(e.message, 'danger'));
}

const EXTERNAL = /(?:src\s*=\s*["']|url\(\s*["']?)(https?:\/\/[^"')\s>]+)/gi;

// http(s) image URLs in the HTML / CSS (<img src>, url(…)); dompdf does not load them
export const externalImages = (...texts) => [...new Set(texts.flatMap((text) => [...text.matchAll(EXTERNAL)].map((m) => m[1])))];

/**
 * Downloads the external images of the template into the image library and returns
 * { replace(text) → text with pdf-asset:… references, imported, failed, images }.
 */
export async function importExternalImages(url, urls) {
    const data = await request(url, { method: 'POST', json: { urls } });
    const imported = Object.entries(data.imported ?? {});

    return {
        imported: imported.length,
        failed: Object.entries(data.failed ?? {}),
        images: data.images,
        replace: (text) => imported.reduce((out, [from, ref]) => out.split(from).join(ref), text),
    };
}
