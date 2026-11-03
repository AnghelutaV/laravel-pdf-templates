// laravel-pdf-templates: image library of the editor menu (partials/images.blade.php, ImageController).
// imageLibrary({ t, insert }) — insert(image) puts the clicked image into the template;
// image = { name, url, ref: 'pdf-asset:name', size }

import { notify } from 'pdf-templates';

const csrf = () => document.querySelector('[data-csrf]')?.dataset.csrf ?? '';

export function imageLibrary({ t, insert }) {
    const section = document.getElementById('pt-images');
    const grid = document.getElementById('pt-images-grid');
    const input = document.getElementById('pt-images-input');
    const drop = document.getElementById('pt-images-drop');
    const listUrl = document.querySelector('[data-pt-images]').dataset.ptImages;

    const request = async (url, options = {}) => {
        const response = await fetch(url, { ...options, headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? response.statusText);
        return data;
    };

    const button = (icon, label, onClick) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'pt-images-action';
        b.dataset.ptTip = label;
        b.setAttribute('aria-label', label);
        b.innerHTML = icon;
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            onClick();
        });
        return b;
    };
    const copyIcon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>';
    const trashIcon = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>';

    function render(images) {
        grid.replaceChildren();
        if (!images.length) {
            const empty = document.createElement('p');
            empty.className = 'pt-muted pt-images-empty';
            empty.textContent = t.images_empty;
            grid.append(empty);
            return;
        }
        images.forEach((image) => {
            const tile = document.createElement('div');
            tile.className = 'pt-images-tile';
            tile.tabIndex = 0;
            tile.dataset.ptTip = `${t.image_insert}: ${image.name}`;

            const img = document.createElement('img');
            img.src = image.url;
            img.alt = image.name;
            img.loading = 'lazy';
            const name = document.createElement('span');
            name.className = 'pt-images-name pt-ellipsis';
            name.textContent = image.name;

            const actions = document.createElement('div');
            actions.className = 'pt-images-actions';
            actions.append(
                button(copyIcon, t.image_copy, async () => {
                    try {
                        await navigator.clipboard.writeText(image.ref);
                        notify(t.image_copied.replace(':ref', image.ref));
                    } catch {
                        prompt(t.image_copy, image.ref);
                    }
                }),
                button(trashIcon, t.delete, async () => {
                    if (!confirm(t.image_delete_confirm.replace(':name', image.name))) return;
                    try {
                        render((await request(`${listUrl}/${encodeURIComponent(image.name)}`, { method: 'DELETE' })).images);
                    } catch (e) {
                        notify(e.message, 'danger');
                    }
                }),
            );

            const use = () => insert(image);
            tile.addEventListener('click', use);
            tile.addEventListener('keydown', (e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), use()));
            tile.append(img, name, actions);
            grid.append(tile);
        });
    }

    async function upload(files) {
        if (!files.length) return;
        const body = new FormData();
        [...files].forEach((file) => body.append('images[]', file));
        drop.classList.add('pt-busy');
        try {
            const data = await request(section.dataset.store, { method: 'POST', body });
            render(data.images);
            notify(t.images_uploaded.replace(':count', data.stored.length));
        } catch (e) {
            notify(e.message, 'danger');
        } finally {
            drop.classList.remove('pt-busy');
            input.value = '';
        }
    }

    input.addEventListener('change', () => upload(input.files));
    // Drag & drop on the upload button or the grid
    [drop, grid].forEach((el) => {
        el.addEventListener('dragover', (e) => {
            e.preventDefault();
            drop.classList.add('pt-dragover');
        });
        el.addEventListener('dragleave', () => drop.classList.remove('pt-dragover'));
        el.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('pt-dragover');
            upload(e.dataTransfer.files);
        });
    });

    request(listUrl).then((data) => render(data.images)).catch((e) => notify(e.message, 'danger'));
}

// Image → natural size, to keep the aspect ratio when it is placed on a pdfme page
export function imageSize(url) {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth || 1, height: img.naturalHeight || 1 });
        img.onerror = () => resolve({ width: 1, height: 1 });
        img.src = url;
    });
}
