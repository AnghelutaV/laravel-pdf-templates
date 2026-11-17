// laravel-pdf-templates: right-click menu and data settings for the WYSIWYG designer (pdfme).
//
// pdfme's designer has no context menu. This one works on the field under the mouse (its canvas element carries
// the field name as title): duplicate, delete, bring to front / send to back, replace an image, "Show only if…"
// (field.showIf) and, for tables, which data key / formula fills each column (field.dataColumns). Both settings are
// read by resources/assets/placeholders.js when the PDF is made, in the browser and in the Node renderer.
// Shift + right click opens the browser's own menu.

import { CODE_TYPES } from 'pdf-templates/placeholders';

const ESCAPE = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
const esc = (value) => String(value ?? '').replace(/[&<>"]/g, (c) => ESCAPE[c]);

// The fields on screen (a page, or the header / footer layer) and the one called `name`
function locate(designer, name) {
    const template = designer.getTemplate();
    const page = template.schemas[designer.getPageCursor()] ?? [];
    const index = page.findIndex((field) => field.name === name);

    return { template, page, index, field: page[index] };
}

// A small form in a dialog: rows [{ label, value, placeholder, hint, multiline, keys }] → the values typed, or null
// when cancelled. keys: data keys shown as buttons that insert {{ key }} at the cursor
export function formDialog(host, { title, intro = '', rows, t }) {
    return new Promise((resolve) => {
        const dialog = document.createElement('dialog');
        dialog.className = 'pt-dialog pt-dialog-sm pt-props';
        dialog.innerHTML = `
            <form method="dialog">
                <div class="pt-card-head"><h2>${esc(title)}</h2></div>
                <div class="pt-props-body">
                    ${intro ? `<p class="pt-muted pt-hint">${intro}</p>` : ''}
                    ${rows.map((row, i) => `
                        <label>
                            <span class="pt-label">${esc(row.label)}</span>
                            ${row.multiline
                                ? `<textarea class="pt-input pt-code-input pt-value-text" data-row="${i}" rows="4" placeholder="${esc(row.placeholder ?? '')}" spellcheck="false">${esc(row.value)}</textarea>`
                                : `<input type="text" class="pt-input pt-code-input" data-row="${i}" value="${esc(row.value)}" placeholder="${esc(row.placeholder ?? '')}" spellcheck="false" autocomplete="off">`}
                            ${row.hint ? `<span class="pt-muted pt-hint">${esc(row.hint)}</span>` : ''}
                            ${row.keys?.length ? `<span class="pt-value-keys">${row.keys.map((key) => `<button type="button" class="pt-chip" data-insert="${i}" data-key="${esc(key)}">{{ ${esc(key)} }}</button>`).join('')}</span>` : ''}
                        </label>`).join('')}
                </div>
                <div class="pt-card-foot"><span></span><span class="pt-actions">
                    <button type="button" class="pt-btn" data-cancel>${esc(t.props_cancel)}</button>
                    <button type="submit" class="pt-btn pt-btn-primary">${esc(t.props_apply)}</button>
                </span></div>
            </form>`;
        host.appendChild(dialog);
        let answer = null;
        dialog.querySelectorAll('[data-insert]').forEach((chip) => chip.addEventListener('click', () => {
            const input = dialog.querySelector(`[data-row="${chip.dataset.insert}"]`);
            const text = `{{ ${chip.dataset.key} }}`;
            const [from, to] = [input.selectionStart ?? input.value.length, input.selectionEnd ?? input.value.length];
            input.value = input.value.slice(0, from) + text + input.value.slice(to);
            input.focus();
            input.setSelectionRange(from + text.length, from + text.length);
        }));
        dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
        dialog.querySelector('form').addEventListener('submit', () => {
            answer = [...dialog.querySelectorAll('[data-row]')].map((input) => input.value.trim());
        });
        dialog.addEventListener('close', () => {
            dialog.remove();
            resolve(answer);
        });
        dialog.showModal();
        dialog.querySelector('[data-row]')?.select();
    });
}

// "Show only if…" for one field; returns true when it changed
export async function editCondition(designer, host, name, t) {
    const { field } = locate(designer, name);
    if (!field) return false;
    const answer = await formDialog(host, {
        title: t.designer_show_if_title.replace(':name', name),
        intro: t.designer_show_if_intro,
        rows: [{ label: t.designer_show_if, value: field.showIf ?? '', placeholder: 'paid · not paid · status == "paid" and total > 100' }],
        t,
    });
    if (answer === null) return false;
    const { template, page, index } = locate(designer, name);
    if (index < 0) return false;
    const next = { ...page[index] };
    if (answer[0]) next.showIf = answer[0];
    else delete next.showIf;
    page[index] = next;
    designer.updateTemplate(template);

    return true;
}

// The value of a QR code / barcode: a fixed text, or a template like "https://pay.example.com/{{ number }}"; true when it changed
export async function editValue(designer, host, name, t, keys = []) {
    const { field } = locate(designer, name);
    if (!field) return false;
    const code = CODE_TYPES.includes(field.type);
    const intro = code ? t.designer_value_intro : (field.readOnly ? t.designer_value_intro_fixed : t.designer_value_intro_text);
    const answer = await formDialog(host, {
        title: t.designer_value_title.replace(':name', name),
        intro: intro.replace(/:name/g, esc(name)),
        rows: [{
            label: t.designer_value,
            value: field.content ?? '',
            placeholder: field.type === 'qrcode' ? 'https://pay.example.com/{{ number }}' : (code ? '{{ sku }}' : 'Invoice {{ number }} for {{ client.name }}'),
            multiline: ['text', 'multiVariableText', 'list'].includes(field.type),
            keys,
        }],
        t,
    });
    if (answer === null) return false;
    return setContent(designer, name, answer[0]);
}

// The test rows of a chart (JSON), used when the data has no key named like the field; true when it changed
export async function editChartData(designer, host, name, t) {
    const { field } = locate(designer, name);
    if (!field) return false;
    const answer = await formDialog(host, {
        title: t.designer_chart_title.replace(':name', name),
        intro: t.designer_chart_intro.replace(/:name/g, esc(name)),
        rows: [{ label: t.designer_chart_data, value: field.content ?? '', placeholder: '[{"label": "Jan", "value": 12}, {"label": "Feb", "value": 19}]' }],
        t,
    });
    if (answer === null) return false;
    try {
        if (answer[0]) JSON.parse(answer[0]);
    } catch {
        window.alert(t.designer_chart_invalid);
        return false;
    }

    return setContent(designer, name, answer[0]);
}

function setContent(designer, name, content) {
    const { template, page, index } = locate(designer, name);
    if (index < 0) return false;
    page[index] = { ...page[index], content };
    designer.updateTemplate(template);

    return true;
}

// Which data key / formula fills each column of a table; returns true when it changed
export async function editColumns(designer, host, name, t) {
    const { field } = locate(designer, name);
    if (!field || field.type !== 'table') return false;
    const head = Array.isArray(field.head) && field.head.length ? field.head : (field.dataColumns ?? ['']);
    const answer = await formDialog(host, {
        title: t.designer_columns_title.replace(':name', name),
        intro: t.designer_columns_intro.replace(':name', esc(name)),
        rows: head.map((label, i) => ({
            label: label || `#${i + 1}`,
            value: field.dataColumns?.[i] ?? '',
            placeholder: i === 0 ? 'name · rowNumber' : 'qty * price|number:2',
        })),
        t,
    });
    if (answer === null) return false;
    const { template, page, index } = locate(designer, name);
    if (index < 0) return false;
    const next = { ...page[index] };
    if (answer.some(Boolean)) next.dataColumns = answer;
    else delete next.dataColumns;
    page[index] = next;
    designer.updateTemplate(template);

    return true;
}

export function designerMenu({ designer, container, t, onChange, onReplaceImage }) {
    const host = container.closest('.pt') ?? document.body;
    let menu = null;
    const close = () => {
        menu?.remove();
        menu = null;
    };

    const changed = () => onChange?.();

    // Field names are unique in the whole template (pages and header / footer)
    const freeName = (template, base) => {
        const taken = new Set([...template.schemas.flat(), ...(template.basePdf?.staticSchema ?? [])].map((field) => field.name));
        const stem = base.replace(/_copy(_\d+)?$/, '');
        let name = `${stem}_copy`;
        for (let i = 2; taken.has(name); i++) name = `${stem}_copy_${i}`;

        return name;
    };

    const actions = {
        duplicate(name) {
            const { template, page, index, field } = locate(designer, name);
            if (!field) return;
            const copy = JSON.parse(JSON.stringify(field));
            copy.name = freeName(template, name);
            copy.position = { x: (field.position?.x ?? 0) + 5, y: (field.position?.y ?? 0) + 5 };
            delete copy.id;
            page.splice(index + 1, 0, copy);
            designer.updateTemplate(template);
            changed();
        },
        remove(name) {
            const { template, page, index } = locate(designer, name);
            if (index < 0) return;
            page.splice(index, 1);
            designer.updateTemplate(template);
            changed();
        },
        // pdfme draws the fields in their order: the last one is on top
        order(name, where) {
            const { template, page, index, field } = locate(designer, name);
            if (index < 0) return;
            page.splice(index, 1);
            if (where === 'front') page.push(field);
            else if (where === 'back') page.unshift(field);
            else page.splice(Math.max(0, Math.min(page.length, index + (where === 'forward' ? 1 : -1))), 0, field);
            designer.updateTemplate(template);
            changed();
        },
        async condition(name) {
            if (await editCondition(designer, host, name, t)) changed();
        },
        async columns(name) {
            if (await editColumns(designer, host, name, t)) changed();
        },
        copyName(name) {
            navigator.clipboard?.writeText(name);
        },
    };

    function items(name) {
        const { page, index, field } = locate(designer, name);
        if (!field) return [];
        const list = [{ hint: true, text: `${name} · ${field.type}` }];
        list.push({ text: t.designer_duplicate, action: () => actions.duplicate(name) });
        if (['image', 'svg'].includes(field.type) && onReplaceImage) {
            list.push({ text: t.field_source_replace, action: () => onReplaceImage(name) });
        }
        list.push('-');
        list.push({ text: field.showIf ? `${t.designer_show_if}: ${field.showIf}` : `${t.designer_show_if}…`, checked: !!field.showIf, action: () => actions.condition(name) });
        if (field.type === 'table') {
            list.push({ text: t.designer_columns, checked: !!field.dataColumns?.some(Boolean), action: () => actions.columns(name) });
        }
        list.push('-');
        list.push({ text: t.designer_order, children: [
            { text: t.designer_front, disabled: index === page.length - 1, action: () => actions.order(name, 'front') },
            { text: t.designer_forward, disabled: index === page.length - 1, action: () => actions.order(name, 'forward') },
            { text: t.designer_backward, disabled: index === 0, action: () => actions.order(name, 'backward') },
            { text: t.designer_back, disabled: index === 0, action: () => actions.order(name, 'back') },
        ] });
        list.push({ text: t.designer_copy_name, action: () => actions.copyName(name) });
        list.push('-');
        list.push({ text: t.delete_field, danger: true, action: () => actions.remove(name) });
        list.push('-', { text: t.ctx_native, hint: true });

        return list;
    }

    function render(list) {
        const ul = document.createElement('ul');
        ul.className = 'pt-ctx-list';
        ul.setAttribute('role', 'menu');
        for (const item of list) {
            const li = document.createElement('li');
            if (item === '-') {
                li.className = 'pt-ctx-sep';
                ul.appendChild(li);
                continue;
            }
            if (item.hint) {
                li.className = 'pt-ctx-hint';
                li.textContent = item.text;
                ul.appendChild(li);
                continue;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pt-ctx-item' + (item.danger ? ' pt-ctx-danger' : '') + (item.checked ? ' pt-ctx-checked' : '');
            button.setAttribute('role', 'menuitem');
            button.textContent = item.text;
            button.disabled = !!item.disabled;
            li.appendChild(button);
            if (item.children) {
                li.classList.add('pt-ctx-parent');
                const sub = render(item.children);
                sub.classList.add('pt-ctx-sub');
                li.appendChild(sub);
                li.addEventListener('mouseenter', () => {
                    sub.classList.remove('pt-ctx-left');
                    if (sub.getBoundingClientRect().right > window.innerWidth - 8) sub.classList.add('pt-ctx-left');
                });
            } else {
                button.addEventListener('click', () => {
                    close();
                    item.action?.();
                });
            }
            ul.appendChild(li);
        }

        return ul;
    }

    // The field under the mouse: the selection frame of pdfme (moveable) may lie on top of it
    const fieldAt = (x, y) => document.elementsFromPoint(x, y).map((el) => el.closest?.('.selectable')).find((el) => el && container.contains(el));

    container.addEventListener('contextmenu', (e) => {
        if (e.shiftKey) return;
        const element = fieldAt(e.clientX, e.clientY);
        const name = element?.getAttribute('title');
        if (!name || !locate(designer, name).field) return;
        e.preventDefault();
        close();
        menu = document.createElement('div');
        menu.className = 'pt-ctx';
        menu.appendChild(render(items(name)));
        host.appendChild(menu);
        const { width, height } = menu.getBoundingClientRect();
        menu.style.left = `${Math.max(8, Math.min(e.clientX, window.innerWidth - width - 8))}px`;
        menu.style.top = `${Math.max(8, Math.min(e.clientY, window.innerHeight - height - 8))}px`;
        menu.querySelector('.pt-ctx-item:not(:disabled)')?.focus({ preventScroll: true });
    });
    document.addEventListener('pointerdown', (e) => menu && !menu.contains(e.target) && close(), true);
    document.addEventListener('keydown', (e) => e.key === 'Escape' && close());
    document.addEventListener('scroll', close, true);
    window.addEventListener('blur', close);
    window.addEventListener('resize', close);

    // Fields with a condition or bound columns get a marker on the canvas (pdfme re-renders it: kept by an observer)
    const mark = () => {
        const page = designer.getTemplate().schemas[designer.getPageCursor()] ?? [];
        const flags = new Map(page.map((field) => [field.name, { condition: !!field.showIf, bound: !!field.dataColumns?.some(Boolean) }]));
        container.querySelectorAll('.selectable').forEach((element) => {
            const flag = flags.get(element.getAttribute('title')) ?? {};
            element.classList.toggle('pt-field-conditional', !!flag.condition);
            element.classList.toggle('pt-field-bound', !!flag.bound);
        });
    };
    let pending = false;
    new MutationObserver(() => {
        if (pending) return;
        pending = true;
        requestAnimationFrame(() => {
            pending = false;
            mark();
        });
    }).observe(container, { childList: true, subtree: true });
    mark();

    return { refresh: mark };
}

// A "Data" box in pdfme's "Edit Field" panel: the field's condition, and for a table its bound columns, with an
// Edit button each (the same dialogs as the right-click menu)
// Fields without a value of their own: shapes, and the ones with their own row (image source, table columns, chart data)
const NO_VALUE = ['image', 'svg', 'table', 'chart', 'line', 'rectangle', 'ellipse'];

// Data paths of an object (client, client.name, items…), for the {{ key }} buttons
function dataKeys(data, prefix = '', depth = 0) {
    if (!data || typeof data !== 'object' || Array.isArray(data) || depth > 2) return [];

    return Object.entries(data).flatMap(([key, value]) => {
        const path = prefix + key;
        return value && typeof value === 'object' && !Array.isArray(value) ? dataKeys(value, `${path}.`, depth + 1) : [path];
    }).slice(0, 40);
}

export function fieldDataBox(designer, container, { t, onChange, sample = () => null }) {
    const host = container.closest('.pt') ?? document.body;

    const decorate = () => {
        const detail = container.querySelector('.pdfme-designer-detail-view');
        const form = detail?.querySelector('form');
        const name = detail?.querySelector('input#name')?.value;
        const { field } = name ? locate(designer, name) : {};
        let box = detail?.querySelector('.pt-field-data');
        if (!form || !field) {
            box?.remove();
            return;
        }
        const bound = field.type === 'table' ? (field.dataColumns ?? []).filter(Boolean).join(', ') : null;
        const code = !NO_VALUE.includes(field.type);
        const chart = field.type === 'chart';
        const key = JSON.stringify([field.name, field.showIf ?? '', bound, code || chart ? field.content ?? '' : null, !!field.readOnly]);
        if (box?.dataset.key === key) return;
        if (!box) {
            box = document.createElement('div');
            box.className = 'pt-field-source pt-field-data';
            // Below the image source box when there is one
            const source = detail.querySelector('.pt-field-source:not(.pt-field-data)');
            form.parentElement.insertBefore(box, source ? source.nextSibling : form);
        }
        box.dataset.key = key;
        box.innerHTML = `
            <span class="pt-field-source-label">${esc(t.designer_show_if)}</span>
            <span class="pt-field-source-value">${field.showIf ? `<code>${esc(field.showIf)}</code>` : esc(t.designer_always)}</span>
            <button type="button" class="pt-field-source-replace" data-edit="condition">${esc(t.designer_edit)}</button>
            ${bound === null ? '' : `
                <span class="pt-field-source-label">${esc(t.designer_columns)}</span>
                <span class="pt-field-source-value">${bound ? `<code>${esc(bound)}</code>` : esc(t.designer_columns_none)}</span>
                <button type="button" class="pt-field-source-replace" data-edit="columns">${esc(t.designer_edit)}</button>`}
            ${code ? `
                <span class="pt-field-source-label">${esc(t.designer_value)}</span>
                <span class="pt-field-source-value">${field.content ? `<code>${esc(field.content)}</code>` : esc(t.designer_value_none)}</span>
                <button type="button" class="pt-field-source-replace" data-edit="value">${esc(t.designer_edit)}</button>
                <span class="pt-muted pt-field-data-hint">${esc((field.readOnly && !CODE_TYPES.includes(field.type) ? t.designer_value_hint_fixed : t.designer_value_hint).replace(':name', field.name))}</span>` : ''}
            ${chart ? `
                <span class="pt-field-source-label">${esc(t.designer_chart_data)}</span>
                <span class="pt-field-source-value">${field.content ? `<code>${esc(field.content)}</code>` : esc(t.designer_value_none)}</span>
                <button type="button" class="pt-field-source-replace" data-edit="chart">${esc(t.designer_edit)}</button>
                <span class="pt-muted pt-field-data-hint">${esc(t.designer_chart_hint.replace(/:name/g, field.name))}</span>` : ''}`;
        box.querySelector('[data-edit="condition"]').addEventListener('click', async () => {
            if (await editCondition(designer, host, field.name, t)) onChange?.();
        });
        box.querySelector('[data-edit="chart"]')?.addEventListener('click', async () => {
            if (await editChartData(designer, host, field.name, t)) onChange?.();
        });
        box.querySelector('[data-edit="value"]')?.addEventListener('click', async () => {
            let data = null;
            try {
                data = JSON.parse(sample() || 'null');
            } catch {
                // invalid test data: no key buttons
            }
            if (await editValue(designer, host, field.name, t, dataKeys(Array.isArray(data) ? data[0] : data))) onChange?.();
        });
        box.querySelector('[data-edit="columns"]')?.addEventListener('click', async () => {
            if (await editColumns(designer, host, field.name, t)) onChange?.();
        });
    };

    new MutationObserver(decorate).observe(container, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
    decorate();
}

/**
 * New fields get pdfme's "field24"-style names; those become names that say what the field is (chart_1, barcode_1,
 * table_2…), the data keys of the API. Only fields not seen before are renamed (`known`: their ids, updated here),
 * so a field of the loaded template, or one the user names "field5", keeps its name.
 * Returns the template with the new names, or null when nothing changed.
 */
export function logicalNames(template, known) {
    const all = (t) => [...(t.schemas ?? []).flat(), ...(t.basePdf?.staticSchema ?? [])].filter(Boolean);
    const key = (field) => field.id ?? field.name;
    const fresh = all(template).filter((field) => !known.has(key(field)) && /^field\d+$/.test(field.name ?? ''));
    all(template).forEach((field) => known.add(key(field)));
    if (!fresh.length) return null;

    const taken = new Set(all(template).map((field) => field.name));
    const BASE = { multiVariableText: 'text', dateTime: 'datetime', radioGroup: 'radio', signature: 'signature' };
    const renames = new Map();
    fresh.forEach((field) => {
        const base = BASE[field.type] ?? String(field.type || 'field').replace(/([a-z])([A-Z])/g, '$1_$2').toLowerCase();
        let n = 1;
        while (taken.has(`${base}_${n}`)) n++;
        taken.add(`${base}_${n}`);
        renames.set(field, `${base}_${n}`);
    });
    const rename = (field) => (renames.has(field) ? { ...field, name: renames.get(field) } : field);

    return {
        ...template,
        schemas: template.schemas.map((page) => page.map(rename)),
        ...(Array.isArray(template.basePdf?.staticSchema) ? { basePdf: { ...template.basePdf, staticSchema: template.basePdf.staticSchema.map(rename) } } : {}),
    };
}
