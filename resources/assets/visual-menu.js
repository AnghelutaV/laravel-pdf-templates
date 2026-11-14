// laravel-pdf-templates: right-click menu and "Properties" dialog for the visual editor (CKEditor 5).
//
// CKEditor 5 has no context menu, only balloons. This one runs its commands (link, table rows / columns / cells,
// images) and edits the inline style / class of the element under the mouse directly in the model
// (General HTML Support keeps them as html<Tag>Attributes), so a style such as "border: 1px solid #000" comes back
// exactly as typed. CKEditor's own table / cell property panels are not used: they rewrite such styles
// (border → border-color only) and dompdf would lose the border.
// Shift + right click opens the browser's own menu (spelling, copy / paste).
import { resolveImages, unresolveImages, notify } from 'pdf-templates';

const ESCAPE = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
const esc = (value) => String(value ?? '').replace(/[&<>"]/g, (c) => ESCAPE[c]);
const attributeKey = (tag) => `html${tag.charAt(0).toUpperCase()}${tag.slice(1)}Attributes`;

// Style fields of the dialog; everything else stays in "Other CSS"
const FIELDS = [
    { key: 'width', label: 'props_width', placeholder: '100% · 40mm · 120px' },
    { key: 'height', label: 'props_height', placeholder: '20mm' },
    { key: 'border', label: 'props_border', placeholder: '1px solid #000' },
    { key: 'padding', label: 'props_padding', placeholder: '2mm 3mm' },
    { key: 'background-color', label: 'props_background', color: true, aliases: ['background'] },
    { key: 'color', label: 'props_color', color: true },
    { key: 'font-size', label: 'props_font_size', placeholder: '10pt' },
    { key: 'line-height', label: 'props_line_height', placeholder: '1.4' },
    { key: 'text-align', label: 'props_align', options: ['', 'left', 'center', 'right', 'justify'] },
    { key: 'vertical-align', label: 'props_valign', options: ['', 'top', 'middle', 'bottom'], cellsOnly: true },
];

export function contextMenu(editor, t) {
    const label = (key) => t[key] ?? key;
    const root = editor.editing.view.getDomRoot();
    const host = root.closest('.pt') ?? document.body;
    const model = editor.model;
    let menu = null;

    const command = (name) => editor.commands.get(name);
    const run = (name, options) => {
        editor.execute(name, options);
        editor.editing.view.focus();
    };
    // A toolbar / balloon button of CKEditor, "clicked" (link, alternative text…)
    const ui = (name) => {
        if (!editor.ui.componentFactory.has(name)) return;
        editor.editing.view.focus();
        editor.ui.componentFactory.create(name).fire('execute');
    };

    const close = () => {
        menu?.remove();
        menu = null;
    };

    // The model element under the mouse, and the caret moved there (unless the selection already covers it)
    function locate(target, x, y) {
        const view = editor.editing.view;
        let viewElement = null;
        for (let node = target; node && !viewElement && node !== root.parentNode; node = node.parentNode) {
            viewElement = view.domConverter.mapDomToView(node) ?? null;
        }
        let element = null;
        for (let v = viewElement; v && !element; v = v.parent) element = editor.editing.mapper.toModelElement(v) ?? null;
        if (!element) return null;

        const up = (name) => {
            for (let el = element; el; el = el.parent) if (el.name === name) return el;
            return null;
        };
        const image = up('imageInline') ?? up('imageBlock');
        const cell = up('tableCell');
        const table = up('table');
        const selection = model.document.selection;

        if (image) {
            model.change((writer) => writer.setSelection(image, 'on'));
        } else {
            const selectedCells = editor.plugins.has('TableSelection') ? editor.plugins.get('TableSelection').getSelectedTableCells() : null;
            const inside = model.createPositionAt(element, 0);
            const keep = (selectedCells?.length > 1 && cell && selectedCells.includes(cell))
                || (!selection.isCollapsed && [...selection.getRanges()].some((range) => range.containsPosition(inside)));
            if (!keep) {
                const dom = document.caretRangeFromPoint?.(x, y);
                const viewRange = dom && view.domConverter.domRangeToView(dom);
                const modelRange = viewRange && editor.editing.mapper.toModelRange(viewRange);
                if (modelRange) model.change((writer) => writer.setSelection(modelRange));
            }
        }

        // The block to style: a paragraph / heading / div / list item, not the hidden paragraph of a table cell
        let block = null;
        for (let el = element; el && el.name !== '$root'; el = el.parent) {
            if (el.name === 'tableCell' || el.name === 'table') break;
            if (model.schema.isBlock(el) && !el.name.startsWith('image')) {
                block = el;
                break;
            }
        }
        if (block?.name === 'paragraph' && cell) block = null;

        return { element, image, cell, table, block, link: !!target.closest('a') || selection.hasAttribute('linkHref') };
    }

    // The tag the element is written as (td / th / p / h2 / div…), for its html<Tag>Attributes key
    function tagOf(element) {
        if (element.name === 'table') return 'table';
        if (element.name === 'imageInline' || element.name === 'imageBlock') return 'img';
        const existing = [...element.getAttributeKeys()].find((key) => /^html[A-Z]\w*Attributes$/.test(key));
        if (existing) return existing.slice(4, -10).toLowerCase();
        const viewElement = editor.editing.mapper.toViewElement(element);

        return viewElement?.name ?? 'p';
    }

    function nameOf(element) {
        if (element.name === 'tableCell') return label('el_cell');
        if (element.name === 'table') return label('el_table');
        if (element.name.startsWith('image')) return label('el_image');
        // heading1…3 are CKEditor's h2…h4; other levels are kept by General HTML Support as htmlH1…
        if (element.name.startsWith('heading') || /^htmlH[1-6]$/.test(element.name)) return label('el_heading');
        if (element.name === 'paragraph') return label('el_paragraph');

        return label('el_block');
    }

    function properties(element) {
        const tag = tagOf(element);
        const key = attributeKey(tag);
        const current = element.getAttribute(key) ?? {};
        const styles = { ...(current.styles ?? {}) };
        const isImage = tag === 'img';
        if (isImage && element.getAttribute('resizedWidth')) styles.width = element.getAttribute('resizedWidth');
        // width="120" / height="40" attributes (HTML from other editors): shown as sizes, written back as CSS
        const attributes = { ...(current.attributes ?? {}) };
        // (CKEditor keeps an image's width / height attributes as model attributes, other tags keep them in GHS)
        const fromAttribute = {};
        for (const size of ['width', 'height']) {
            const raw = attributes[size] ?? (isImage ? element.getAttribute(size) : null);
            if (raw && !styles[size]) {
                styles[size] = fromAttribute[size] = /^\d+(\.\d+)?$/.test(String(raw)) ? `${raw}px` : String(raw);
            }
        }

        // Image source as it is stored: a library image as pdf-asset:…, a URL or {placeholder} as typed
        const imageSource = isImage ? unresolveImages({ s: element.getAttribute('src') ?? '' }).s : '';
        const embedded = imageSource.startsWith('data:');
        const imageLink = isImage ? (element.getAttribute('linkHref') ?? '') : '';

        const values = {};
        for (const field of FIELDS) {
            const found = [field.key, ...(field.aliases ?? [])].find((k) => k in styles);
            values[field.key] = found ? styles[found] : '';
            if (found) delete styles[found];
        }
        const other = Object.entries(styles).map(([k, v]) => `${k}: ${v};`).join('\n');
        const fields = FIELDS.filter((field) => !field.cellsOnly || element.name === 'tableCell');

        const dialog = document.createElement('dialog');
        dialog.className = 'pt-dialog pt-dialog-sm pt-props';
        dialog.innerHTML = `
            <form method="dialog">
                <div class="pt-card-head"><h2>${esc(label('props_title').replace(':name', nameOf(element)))} <code>&lt;${esc(tag)}&gt;</code></h2></div>
                <div class="pt-props-body">
                    ${isImage ? `
                        <label>
                            <span class="pt-label">${esc(label('props_src'))}</span>
                            <input type="text" class="pt-input" data-src value="${embedded ? '' : esc(imageSource)}"
                                placeholder="${esc(embedded ? label('props_src_embedded').replace(':size', Math.round(imageSource.length * 0.75 / 1024)) : 'https://… · pdf-asset:logo.png · {logo}')}">
                            ${/^https?:/i.test(imageSource) ? `<span class="pt-muted pt-hint pt-props-warn">${esc(label('props_src_remote'))}</span>` : ''}
                        </label>
                        <label>
                            <span class="pt-label">${esc(label('props_link'))}</span>
                            <input type="text" class="pt-input" data-link value="${esc(imageLink)}" placeholder="https://…">
                        </label>` : ''}
                    <div class="pt-props-grid">
                        ${fields.map((field) => `
                            <label>
                                <span class="pt-label">${esc(label(field.label))}</span>
                                ${field.options
                                    ? `<select class="pt-select" data-key="${field.key}">${field.options.map((o) => `<option value="${o}" ${o === values[field.key] ? 'selected' : ''}>${o || '—'}</option>`).join('')}</select>`
                                    : `<span class="pt-props-input">
                                        ${field.color ? `<input type="color" data-color-for="${field.key}" value="${/^#[0-9a-f]{6}$/i.test(values[field.key]) ? values[field.key] : '#ffffff'}">` : ''}
                                        <input type="text" class="pt-input" data-key="${field.key}" value="${esc(values[field.key])}" placeholder="${esc(field.placeholder ?? '')}">
                                      </span>`}
                            </label>`).join('')}
                    </div>
                    <label>
                        <span class="pt-label">${esc(label('props_class'))}</span>
                        <input type="text" class="pt-input" data-class value="${esc((current.classes ?? []).join(' '))}">
                    </label>
                    <label>
                        <span class="pt-label">${esc(label('props_other'))}</span>
                        <textarea class="pt-textarea pt-code" data-other rows="4" spellcheck="false">${esc(other)}</textarea>
                    </label>
                </div>
                <div class="pt-card-foot">
                    <span></span>
                    <span class="pt-actions">
                        <button type="button" class="pt-btn" data-cancel>${esc(label('props_cancel'))}</button>
                        <button type="submit" class="pt-btn pt-btn-primary">${esc(label('props_apply'))}</button>
                    </span>
                </div>
            </form>`;
        host.appendChild(dialog);
        dialog.querySelectorAll('[data-color-for]').forEach((picker) => picker.addEventListener('input', () => {
            dialog.querySelector(`[data-key="${picker.dataset.colorFor}"]`).value = picker.value;
        }));
        dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => dialog.remove());
        dialog.querySelector('form').addEventListener('submit', async () => {
            const next = {};
            const srcInput = dialog.querySelector('[data-src]');
            const newSource = srcInput ? srcInput.value.trim() : '';
            // Resolved before the model change (a library image becomes its data URL in the editor)
            const resolvedSource = newSource && newSource !== imageSource ? (await resolveImages({ s: newSource })).s : null;
            const newLink = dialog.querySelector('[data-link]')?.value.trim() ?? '';
            // "Other CSS" first, the fields win over it
            for (const declaration of dialog.querySelector('[data-other]').value.split(';')) {
                const at = declaration.indexOf(':');
                if (at > 0) next[declaration.slice(0, at).trim().toLowerCase()] = declaration.slice(at + 1).trim();
            }
            dialog.querySelectorAll('[data-key]').forEach((input) => {
                const value = input.value.trim();
                // A bare number for a size ("120") means px; line height stays a number
                if (value) next[input.dataset.key] = /^\d+(\.\d+)?$/.test(value) && input.dataset.key !== 'line-height' && Number(value) !== 0 ? `${value}px` : value;
            });
            const classes = dialog.querySelector('[data-class]').value.split(/\s+/).filter(Boolean);
            // Only one side changed: the other one, if it only came from a width / height attribute, is dropped
            // so the proportions are kept
            for (const [changed, other] of [['width', 'height'], ['height', 'width']]) {
                if (next[changed] !== fromAttribute[changed] && fromAttribute[other] && next[other] === fromAttribute[other]) delete next[other];
            }

            model.change((writer) => {
                if (isImage) {
                    // The size typed here replaces the width / height attributes (they would fight with it in dompdf)
                    if (next.width || next.height) {
                        writer.removeAttribute('width', element);
                        writer.removeAttribute('height', element);
                    }
                    if (next.width) writer.setAttribute('resizedWidth', next.width, element);
                    else writer.removeAttribute('resizedWidth', element);
                    delete next.width;
                    if (resolvedSource) {
                        writer.setAttribute('src', resolvedSource, element);
                        writer.removeAttribute('srcset', element);
                    }
                    if (newLink !== imageLink && model.schema.checkAttribute(element, 'linkHref')) {
                        if (newLink) writer.setAttribute('linkHref', newLink, element);
                        else writer.removeAttribute('linkHref', element);
                    }
                }
                const value = { ...current };
                // Sizes now live in the style
                const leftAttributes = { ...attributes };
                delete leftAttributes.width;
                delete leftAttributes.height;
                if (Object.keys(leftAttributes).length) value.attributes = leftAttributes;
                else delete value.attributes;
                if (Object.keys(next).length) value.styles = next;
                else delete value.styles;
                if (classes.length) value.classes = classes;
                else delete value.classes;
                if (Object.keys(value).length) writer.setAttribute(key, value, element);
                else writer.removeAttribute(key, element);
            });
            editor.editing.view.focus();
        });
        dialog.showModal();
        dialog.querySelector('[data-key]')?.focus();
    }

    // A small prompt inside the page (image URL…), resolves to the text or null
    function ask(title, value = '', placeholder = '') {
        return new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'pt-dialog pt-dialog-sm pt-props';
            dialog.innerHTML = `
                <form method="dialog">
                    <div class="pt-card-head"><h2>${esc(title)}</h2></div>
                    <div class="pt-props-body"><input type="text" class="pt-input" value="${esc(value)}" placeholder="${esc(placeholder)}"></div>
                    <div class="pt-card-foot"><span></span><span class="pt-actions">
                        <button type="button" class="pt-btn" data-cancel>${esc(label('props_cancel'))}</button>
                        <button type="submit" class="pt-btn pt-btn-primary">${esc(label('props_apply'))}</button>
                    </span></div>
                </form>`;
            host.appendChild(dialog);
            const input = dialog.querySelector('input');
            let answer = null;
            dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
            dialog.querySelector('form').addEventListener('submit', () => answer = input.value.trim());
            // null = cancelled, '' = applied empty
            dialog.addEventListener('close', () => {
                dialog.remove();
                resolve(answer);
            });
            dialog.showModal();
            input.select();
        });
    }

    // "Show only if…": a condition around the element, kept in its data-pt-before / data-pt-after attributes
    // ({#if paid} … {/if}, or {#unless paid} for "not paid"); the innermost pair is the one edited here
    async function visibility(element) {
        const key = attributeKey(tagOf(element));
        const current = element.getAttribute(key) ?? {};
        const attributes = { ...(current.attributes ?? {}) };
        let before = decodeURIComponent(attributes['data-pt-before'] ?? '');
        let after = decodeURIComponent(attributes['data-pt-after'] ?? '');
        const open = /\{#(if|unless)\s+([^{}]+)\}$/.exec(before);
        const has = !!open && after.startsWith(`{/${open[1]}}`);

        const answer = await ask(label('ctx_visibility_title'), has ? (open[1] === 'unless' ? 'not ' : '') + open[2].trim() : '', 'paid · not paid · total > 100');
        if (answer === null) return;
        // "not paid" → {#unless paid}; any other condition ("status == 'paid' and total > 100") → {#if …}
        let [, not, condition] = /^(not\s+|!\s*)?(.*)$/.exec(answer);
        if (not && !/^@?[A-Za-z_][\w.@-]*$/.test(condition)) [not, condition] = [null, answer];
        if (/[{}]/.test(condition)) {
            notify(label('ctx_visibility_invalid'), 'danger');
            return;
        }
        if (has) {
            before = before.slice(0, open.index);
            after = after.slice(open[1].length + 3);
        }
        if (condition) {
            const type = not ? 'unless' : 'if';
            before += `{#${type} ${condition}}`;
            after = `{/${type}}` + after;
        }
        for (const [name, value] of [['data-pt-before', before], ['data-pt-after', after]]) {
            if (value) attributes[name] = encodeURIComponent(value);
            else delete attributes[name];
        }
        model.change((writer) => {
            const value = { ...current };
            if (Object.keys(attributes).length) value.attributes = attributes;
            else delete value.attributes;
            if (Object.keys(value).length) writer.setAttribute(key, value, element);
            else writer.removeAttribute(key, element);
        });
        editor.editing.view.focus();
    }

    function items(ctx) {
        const list = [];
        const sep = () => list.length && list.at(-1) !== '-' && list.push('-');
        const cmd = (text, name, options) => ({ text: label(text), disabled: !command(name)?.isEnabled, action: () => run(name, options) });

        if (ctx.link) {
            list.push({ text: label('ctx_edit_link'), action: () => ui(ctx.image ? 'linkImage' : 'link') });
            list.push(cmd('ctx_remove_link', 'unlink'));
        } else if (ctx.image || !model.document.selection.isCollapsed) {
            list.push({ text: label('ctx_add_link'), action: () => ui(ctx.image ? 'linkImage' : 'link') });
        }

        if (ctx.image) {
            sep();
            list.push({
                text: label('ctx_replace_image'),
                disabled: !command('replaceImageSource')?.isEnabled,
                action: async () => {
                    const now = unresolveImages({ s: ctx.image.getAttribute('src') ?? '' }).s;
                    const url = await ask(label('ctx_image_url'), now.startsWith('data:') ? '' : now, 'https://… · pdf-asset:logo.png · {logo}');
                    if (url) run('replaceImageSource', { source: (await resolveImages({ s: url })).s });
                },
            });
            list.push({ text: label('ctx_alt_text'), action: () => ui('imageTextAlternative') });
            list.push({ text: label('props_title').replace(':name', label('el_image')), action: () => properties(ctx.image) });
            list.push({ text: label('ctx_delete_image'), danger: true, action: () => model.change((writer) => writer.remove(ctx.image)) });
        }

        if (ctx.cell) {
            sep();
            list.push({
                text: label('ctx_cell'),
                children: [
                    cmd('ctx_merge_cells', 'mergeTableCells'),
                    cmd('ctx_merge_right', 'mergeTableCellRight'),
                    cmd('ctx_merge_down', 'mergeTableCellDown'),
                    cmd('ctx_split_v', 'splitTableCellVertically'),
                    cmd('ctx_split_h', 'splitTableCellHorizontally'),
                    '-',
                    { text: label('props_title').replace(':name', label('el_cell')), action: () => properties(ctx.cell) },
                ],
            });
            list.push({
                text: label('ctx_row'),
                children: [
                    cmd('ctx_insert_above', 'insertTableRowAbove'),
                    cmd('ctx_insert_below', 'insertTableRowBelow'),
                    { ...cmd('ctx_header_row', 'setTableRowHeader'), checked: !!command('setTableRowHeader')?.value },
                    { text: label('ctx_visibility'), action: () => visibility(ctx.cell.parent) },
                    '-',
                    { ...cmd('ctx_delete_row', 'removeTableRow'), danger: true },
                ],
            });
            list.push({
                text: label('ctx_column'),
                children: [
                    cmd('ctx_insert_left', 'insertTableColumnLeft'),
                    cmd('ctx_insert_right', 'insertTableColumnRight'),
                    { ...cmd('ctx_header_column', 'setTableColumnHeader'), checked: !!command('setTableColumnHeader')?.value },
                    '-',
                    { ...cmd('ctx_delete_column', 'removeTableColumn'), danger: true },
                ],
            });
            list.push({ text: label('props_title').replace(':name', label('el_table')), action: () => properties(ctx.table) });
            list.push({ text: label('ctx_visibility_table'), action: () => visibility(ctx.table) });
            list.push({ text: label('ctx_delete_table'), danger: true, action: () => model.change((writer) => writer.remove(ctx.table)) });
        }

        if (ctx.block) {
            sep();
            list.push({ text: label('props_title').replace(':name', nameOf(ctx.block)), action: () => properties(ctx.block) });
            list.push({ text: label('ctx_visibility'), action: () => visibility(ctx.block) });
        }
        if (command('removeFormat')) {
            sep();
            list.push(cmd('ctx_remove_format', 'removeFormat'));
        }
        list.push('-', { text: label('ctx_native'), hint: true });

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
                button.setAttribute('aria-haspopup', 'true');
                const sub = render(item.children);
                sub.classList.add('pt-ctx-sub');
                li.appendChild(sub);
                // Open to the left when there is no room on the right
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

    root.addEventListener('contextmenu', (e) => {
        if (e.shiftKey) return;
        const ctx = locate(e.target, e.clientX, e.clientY);
        if (!ctx) return;
        e.preventDefault();
        close();
        menu = document.createElement('div');
        menu.className = 'pt-ctx';
        menu.appendChild(render(items(ctx)));
        host.appendChild(menu);
        // Inside the window
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
}
