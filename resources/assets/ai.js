// laravel-pdf-templates: AI assistant panel (components/ai-panel.blade.php), Copilot-like chat on the right.
// The answer is applied to the editor as unsaved changes; every applied answer can be undone.
//
// assistant({ url, t, key, getDraft, applyDraft, initial })
//   initial           → first messages of a template made with "Generate with AI" (opens the panel)
//   getDraft()        → current editor content { html, css, sample_data, ... } (strings)
//   applyDraft(patch) → write some of those keys into the editor (marks it dirty, refreshes the preview)

const csrf = () => document.querySelector('[data-csrf]')?.dataset.csrf ?? '';

const storage = (store, key, value) => {
    try {
        if (value === undefined) return JSON.parse(store.getItem(key) ?? 'null');
        if (value === null) store.removeItem(key);
        else store.setItem(key, JSON.stringify(value));
    } catch {
        return null;
    }
};

export function assistant({ url, t, key, getDraft, applyDraft, initial = [] }) {
    const $ = (id) => document.getElementById(id);
    const shell = $('pt-shell');
    const panel = $('pt-ai');
    const toggle = $('pt-ai-toggle');
    const log = $('pt-ai-log');
    const empty = $('pt-ai-empty');
    const input = $('pt-ai-input');
    const send = $('pt-ai-send');
    const fileInput = $('pt-ai-file');
    const chip = $('pt-ai-file-chip');
    const historyKey = 'pt-ai-history-' + key;

    // [{ role: 'user' | 'assistant', content }] for this template, kept for the browser tab
    let history = storage(sessionStorage, historyKey) ?? [];
    let controller = null;
    let file = null;

    const setOpen = (open) => {
        panel.hidden = !open;
        shell.classList.toggle('pt-ai-open', open);
        toggle.setAttribute('aria-pressed', open);
        storage(localStorage, 'pt-ai-open', open);
        // The designer / textareas follow the new width
        window.dispatchEvent(new Event('resize'));
        if (open) input.focus();
    };
    toggle.addEventListener('click', () => setOpen(panel.hidden));
    panel.querySelector('[data-pt-ai-close]').addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') {
            e.preventDefault();
            setOpen(panel.hidden);
        }
    });

    const scroll = () => log.scrollTop = log.scrollHeight;
    const refreshEmpty = () => empty.classList.toggle('pt-hidden', log.querySelector('.pt-ai-msg') !== null);

    function bubble(role, text, extra = '') {
        const el = document.createElement('div');
        el.className = `pt-ai-msg pt-ai-${role} ${extra}`;
        const body = document.createElement('div');
        body.className = 'pt-ai-text';
        body.textContent = text;
        el.append(body);
        log.append(el);
        refreshEmpty();
        scroll();
        return el;
    }

    // "Changed: HTML, CSS" + Undo (restores what the editor had before this answer)
    function changesBar(el, changes, before) {
        const keys = Object.keys(changes);
        const bar = document.createElement('div');
        bar.className = 'pt-ai-changes';
        const label = document.createElement('span');
        label.textContent = t.ai_changed + ' ' + keys.map((k) => t['ai_field_' + k] ?? k).join(', ');
        const undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'pt-btn pt-btn-ghost pt-btn-sm';
        undo.textContent = t.ai_undo;
        undo.addEventListener('click', () => {
            applyDraft(Object.fromEntries(keys.map((k) => [k, before[k] ?? ''])));
            undo.disabled = true;
            undo.textContent = t.ai_undone;
        });
        bar.append(label, undo);
        el.append(bar);
    }

    const setBusy = (busy) => {
        send.classList.toggle('pt-ai-busy', busy);
        send.setAttribute('aria-label', busy ? t.ai_stop : t.ai_send);
        send.dataset.ptTip = busy ? t.ai_stop : t.ai_send;
    };

    const setFile = (next) => {
        file = next;
        fileInput.value = '';
        chip.classList.toggle('pt-hidden', !file);
        chip.querySelector('span').textContent = file?.name ?? '';
    };
    fileInput.addEventListener('change', () => setFile(fileInput.files[0] ?? null));
    chip.querySelector('button').addEventListener('click', () => setFile(null));

    async function ask(message) {
        if (controller || !message.trim()) return;
        const attached = file;
        bubble('user', message + (attached ? `\n📎 ${attached.name}` : ''));
        input.value = '';
        setFile(null);
        const thinking = bubble('assistant', t.ai_thinking, 'pt-ai-thinking');

        const body = new FormData();
        body.append('message', message);
        body.append('draft', JSON.stringify(getDraft()));
        body.append('history', JSON.stringify(history));
        if (attached) body.append('file', attached);

        controller = new AbortController();
        setBusy(true);
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                body,
                signal: controller.signal,
            });
            const data = await response.json().catch(() => ({ error: response.statusText }));
            if (!response.ok) {
                throw new Error(data.error ?? Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? response.statusText);
            }

            thinking.remove();
            const changes = data.changes ?? {};
            const reply = data.reply || (Object.keys(changes).length ? t.ai_done : t.ai_no_changes);
            const el = bubble('assistant', reply);
            if (Object.keys(changes).length) {
                const before = getDraft();
                applyDraft(changes);
                changesBar(el, changes, before);
            }

            history.push({ role: 'user', content: message }, { role: 'assistant', content: reply });
            storage(sessionStorage, historyKey, history.slice(-40));
        } catch (e) {
            thinking.remove();
            if (e.name === 'AbortError') return;
            const el = bubble('assistant', e.message, 'pt-ai-error');
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'pt-btn pt-btn-ghost pt-btn-sm';
            retry.textContent = t.ai_retry;
            retry.addEventListener('click', () => {
                el.remove();
                if (attached) setFile(attached);
                ask(message);
            });
            el.append(retry);
        } finally {
            controller = null;
            setBusy(false);
            scroll();
        }
    }

    send.addEventListener('click', () => controller ? controller.abort() : ask(input.value));
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            ask(input.value);
        }
    });
    panel.querySelectorAll('[data-pt-ai-suggest]').forEach((b) => b.addEventListener('click', () => ask(b.textContent.trim())));
    panel.querySelector('[data-pt-ai-new]').addEventListener('click', () => {
        controller?.abort();
        history = [];
        storage(sessionStorage, historyKey, null);
        log.querySelectorAll('.pt-ai-msg').forEach((m) => m.remove());
        refreshEmpty();
        input.focus();
    });

    // Just generated from the list: its description + answer start the chat
    if (initial.length) {
        history = [...history, ...initial];
        storage(sessionStorage, historyKey, history);
    }

    // Earlier messages of this tab (no undo: the editor was reloaded since)
    history.forEach((m) => bubble(m.role, m.content));
    if (initial.length || storage(localStorage, 'pt-ai-open')) setOpen(true);
}
