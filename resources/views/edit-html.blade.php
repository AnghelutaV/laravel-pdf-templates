@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php
        // The default font + config html.fonts + the fonts added in the menu (FontLibrary)
        $fontNames = collect(app(\AnghelutaV\PdfTemplates\FontLibrary::class)->families());
        $fonts = $fontNames->map(fn($font) => '<code>' . e($font) . '</code>')->implode(', ');
    @endphp
    {{-- Editor first: a slim strip + code / preview; everything else lives in the off-canvas menu.
         Saved over JSON (Ctrl+S); the form is the no-JS fallback. --}}
    <form method="post" action="{{ $ptRoute('update', $template) }}" id="pt-form">
        @csrf
        @method('PUT')
        <div class="pt pt-compact pt-focus" id="pt-shell" data-csrf="{{ csrf_token() }}" data-pt-images="{{ $ptRoute('images.index') }}">
            <x-pdf-templates::editor :template="$template" :versions="$versions ?? null">
                <section class="pt-drawer-section" id="pt-section-check">
                    <h3>{{ __('pdf-templates::ui.section_check') }}</h3>
                    <a class="pt-btn pt-btn-block" href="{{ $ptRoute('test', $template) }}" data-pt-leave>
                        <x-pdf-templates::icon name="flask" :size="15" /> {{ __('pdf-templates::ui.test') }}
                    </a>
                </section>

                <section class="pt-drawer-section" id="pt-section-page">
                    <h3>{{ __('pdf-templates::ui.section_page') }}</h3>
                    <div class="pt-stack pt-stack-2">
                        <div>
                            <label class="pt-label" for="pt-paper">{{ __('pdf-templates::ui.paper') }}</label>
                            <select class="pt-select pt-btn-block" id="pt-paper" name="paper">
                                @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::PAPERS as $paper)
                                    <option value="{{ $paper }}" @selected($template->paper === $paper)>{{ strtoupper($paper) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="pt-label" for="pt-orientation">{{ __('pdf-templates::ui.orientation') }}</label>
                            <select class="pt-select pt-btn-block" id="pt-orientation" name="orientation">
                                @foreach (\AnghelutaV\PdfTemplates\Models\PdfTemplate::ORIENTATIONS as $orientation)
                                    <option value="{{ $orientation }}" @selected($template->orientation === $orientation)>{{ __('pdf-templates::ui.' . $orientation) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <p class="pt-muted pt-hint" style="margin-top: 10px;">{!! __('pdf-templates::ui.fonts_hint', ['fonts' => $fonts]) !!}</p>
                </section>

                @include('pdf-templates::partials.page-setup')

                @include('pdf-templates::partials.images', ['importExternal' => true])

                <section class="pt-drawer-section" id="pt-section-file">
                    <h3>{{ __('pdf-templates::ui.section_file') }}</h3>
                    <a class="pt-btn pt-btn-block" href="{{ $ptRoute('export', $template) }}">
                        <x-pdf-templates::icon name="download" :size="15" /> {{ __('pdf-templates::ui.export') }}
                    </a>
                </section>
            </x-pdf-templates::editor>

            <div class="pt-split pt-even pt-workspace" id="pt-workspace">
                <div class="pt-card">
                    <div class="pt-tabs" id="pt-tabs" role="tablist">
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-html" id="pt-mode-code"><x-pdf-templates::icon name="code" :size="14" /> HTML</button>
                        @if (\AnghelutaV\PdfTemplates\Renderers\HtmlRenderer::editor()['enabled'])
                            {{-- Same panel as HTML: the visual editor (CKEditor 5, loaded on first use) in place of the code --}}
                            <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-html" id="pt-mode-visual"><x-pdf-templates::icon name="pencil" :size="14" /> {{ __('pdf-templates::ui.mode_visual') }}</button>
                        @endif
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-css"><x-pdf-templates::icon name="layout" :size="14" /> CSS</button>
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-data"><x-pdf-templates::icon name="braces" :size="14" /> {{ __('pdf-templates::ui.tab_data') }}</button>
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-syntax"><x-pdf-templates::icon name="file" :size="14" /> {{ __('pdf-templates::ui.tab_syntax') }}</button>
                        {{-- Preview controls live here so the preview gets the whole height --}}
                        <div class="pt-tabs-tools">
                            <span class="pt-muted" id="pt-status"></span>
                            <label class="pt-check pt-muted" data-pt-tip="{{ __('pdf-templates::ui.preview') }}"><input type="checkbox" class="pt-switch" id="pt-auto" checked> {{ __('pdf-templates::ui.auto') }}</label>
                            <button type="button" class="pt-btn pt-btn-sm" id="pt-preview"><x-pdf-templates::icon name="play" :size="12" /> {{ __('pdf-templates::ui.generate') }}</button>
                        </div>
                    </div>
                    <div class="pt-card-body">
                        <div class="pt-panel" id="pt-tab-html">
                            @if (\AnghelutaV\PdfTemplates\Renderers\HtmlRenderer::editor()['enabled'])
                                {{-- The textarea is always the source of truth; the Visual tab shows this editor in its place --}}
                                <div class="pt-html-mode pt-hidden" id="pt-visual-note"><span class="pt-muted pt-hint">{{ __('pdf-templates::ui.visual_note') }}</span></div>
                                <div class="pt-layout-warning pt-hidden" id="pt-layout-warning"><x-pdf-templates::icon name="alert" :size="14" /> <span><span data-flex>{!! __('pdf-templates::ui.layout_warning') !!}</span> <span data-absolute>{!! __('pdf-templates::ui.layout_warning_absolute') !!}</span></span></div>
                                <div class="pt-visual pt-hidden" id="pt-visual"><div id="pt-visual-editor"></div></div>
                            @endif
                            <textarea class="pt-textarea pt-code" id="pt-html" name="html" spellcheck="false" aria-label="HTML">{{ old('html', $template->html) }}</textarea>
                        </div>
                        <div class="pt-panel" id="pt-tab-css">
                            <textarea class="pt-textarea pt-code" id="pt-css" name="css" spellcheck="false" aria-label="CSS">{{ old('css', $template->css) }}</textarea>
                        </div>
                        <div class="pt-panel" id="pt-tab-data">
                            <textarea class="pt-textarea pt-code" id="pt-sample" name="sample_data" spellcheck="false" aria-label="JSON">{{ old('sample_data', $template->sample_data) }}</textarea>
                        </div>
                        <div class="pt-panel pt-panel-scroll" id="pt-tab-syntax">
                            <table class="pt-table pt-syntax">
                                @foreach (__('pdf-templates::ui.syntax') as [$code, $description])
                                    <tr><td><code>{{ $code }}</code></td><td>{{ $description }}</td></tr>
                                @endforeach
                            </table>
                            <p class="pt-muted" style="margin: 12px 10px;">{!! __('pdf-templates::ui.multi_note') !!}</p>
                        </div>
                    </div>
                </div>

                <div class="pt-card pt-preview-card" id="pt-preview-card">
                    <button type="button" class="pt-btn pt-btn-sm pt-btn-icon pt-preview-fs" id="pt-preview-fs"
                        data-pt-tip="{{ __('pdf-templates::ui.fullscreen') }}" aria-label="{{ __('pdf-templates::ui.fullscreen') }}">
                        <x-pdf-templates::icon name="maximize" :size="14" data-pt-fs="off" />
                        <x-pdf-templates::icon name="minimize" :size="14" data-pt-fs="on" class="pt-hidden" />
                    </button>
                    <div class="pt-card-body pt-preview-body">
                        <div class="pt-alert pt-alert-danger pt-alert-dismissible pt-hidden" id="pt-error" role="alert">
                        <span data-pt-alert-text></span>
                        <button type="button" class="pt-alert-close" data-pt-dismiss data-pt-tip="{{ __('pdf-templates::ui.close') }}" aria-label="{{ __('pdf-templates::ui.close') }}"><x-pdf-templates::icon name="x" :size="14" /></button>
                    </div>
                        <iframe class="pt-frame" id="pt-frame" title="{{ __('pdf-templates::ui.preview') }}"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script type="module">
        import { renderOnServer, showPdf, codeTextarea, tabs, fillViewport, notify, saveTemplate, editorChrome, fullscreenToggle, resolveImages } from 'pdf-templates';
        import { pageSetup, fontLibrary, externalImages, importExternalImages } from 'pdf-templates/html-tools';

        // Code + preview take the height down to the bottom of the window; stacked (natural height) on small screens
        fillViewport(document.getElementById('pt-workspace'), { gap: 8, minWidth: 1100 });

        const t = @json(__('pdf-templates::ui'));
        const renderUrl = @json($ptRoute('render', $template));
        const updateUrl = @json($ptRoute('update', $template));
        const form = document.getElementById('pt-form');
        const [html, css, sample, paper, orientation] = ['pt-html', 'pt-css', 'pt-sample', 'pt-paper', 'pt-orientation']
            .map((id) => document.getElementById(id));
        const fields = ['pt-name', 'pt-slug', 'pt-description', 'pt-active', 'pt-category'].map((id) => document.getElementById(id));
        const status = document.getElementById('pt-status');
        const errorBox = document.getElementById('pt-error');
        let timer;
        let seq = 0;

        tabs(document.getElementById('pt-tabs'));
        [html, css, sample].forEach(codeTextarea);

        async function save() {
            const [name, slug, description, active, category] = fields;
            try {
                const res = await saveTemplate(updateUrl, {
                    name: name.value,
                    slug: slug.value,
                    description: description.value,
                    category: category.value,
                    active: active.checked ? 1 : 0,
                    html: html.value,
                    css: css.value,
                    sample_data: sample.value,
                    paper: paper.value,
                    orientation: orientation.value,
                    settings: setup.value(),
                });
                chrome.setDirty(false);
                notify(t.saved_at.replace(':time', res.updated_at));
                return true;
            } catch (e) {
                notify(t.save_error.replace(':error', e.message), 'danger');
                return false;
            }
        }

        const chrome = editorChrome({ save, t });
        // Enter in a settings field submits the form: save over JSON instead of reloading
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            document.getElementById('pt-save').click();
        });
        [...fields, html, css, sample, paper, orientation].forEach((el) => el.addEventListener('input', () => chrome.setDirty(true)));
        [paper, orientation].forEach((el) => el.addEventListener('change', () => chrome.setDirty(true)));

        // Auto preview skips a render when nothing changed since the last one (typing then undoing, arrow keys…)
        let lastRendered = null;
        async function preview(force = false) {
            // settings: always sent ('{}' = none), so unsaved page setup edits show and removed ones go away
            const fields = { html: html.value, css: css.value, data: sample.value, paper: paper.value, orientation: orientation.value, settings: JSON.stringify(setup.value() ?? {}) };
            const key = JSON.stringify(fields);
            if (!force && key === lastRendered) return;
            lastRendered = key;

            const current = ++seq;
            status.textContent = t.generating;
            try {
                const blob = await renderOnServer(renderUrl, fields);
                if (current !== seq) return;
                showPdf(document.getElementById('pt-frame'), blob);
                errorBox.classList.add('pt-hidden');
                status.textContent = new Date().toLocaleTimeString();
            } catch (e) {
                if (current !== seq) return;
                lastRendered = null; // a failed render may be retried with the same content
                errorBox.querySelector('[data-pt-alert-text]').textContent = e.message;
                errorBox.classList.remove('pt-hidden');
                status.textContent = '';
            }
        }

        document.getElementById('pt-preview').addEventListener('click', () => preview(true));

        // Page setup (margins, header / footer, word break) and fonts, in the menu
        const schedulePreview = () => {
            if (!document.getElementById('pt-auto').checked) return;
            clearTimeout(timer);
            timer = setTimeout(preview, 900);
        };
        const setup = pageSetup({
            initial: {{ Illuminate\Support\Js::from($template->settings) }},
            onChange() {
                chrome.setDirty(true);
                schedulePreview();
            },
        });
        fontLibrary({ t, onChange: () => preview(true) });

        // Full screen on the PDF preview alone
        fullscreenToggle(document.getElementById('pt-preview-fs'), document.getElementById('pt-preview-card'), t);
        [html, css, sample, paper, orientation].forEach((el) => el.addEventListener('input', () => {
            if (!document.getElementById('pt-auto').checked) return;
            clearTimeout(timer);
            timer = setTimeout(preview, 900);
        }));
        preview();

        // Visual editing of the HTML (CKEditor 5, loaded from its CDN on first use). The textarea stays the source of
        // truth: every edit made in the visual editor is written to it, so save / preview / AI work the same.
        const visualConfig = {{ Illuminate\Support\Js::from(\AnghelutaV\PdfTemplates\Renderers\HtmlRenderer::editor()) }};
        const visualFonts = {{ Illuminate\Support\Js::from($fontNames) }};
        const modeCode = document.getElementById('pt-mode-code');
        const modeVisual = document.getElementById('pt-mode-visual');
        const visualBox = document.getElementById('pt-visual');
        let visual = null;
        let visualOn = false;
        let fromVisual = false;

        // The template's CSS styles the editing area too, scoped to it (CSS nesting) so it cannot reach the admin UI
        const visualStyle = document.head.appendChild(document.createElement('style'));
        // The editing area looks like the PDF. Two scopes: the PDF's defaults (dompdf) beat CKEditor's own content
        // styles (tables 100% wide with grey borders and padding, grey <th>, no margins on <p> in cells, left <th>);
        // the template's CSS has a heavier scope still, so it beats both. body / html rules apply to the area itself.
        const defaultFont = @json((string) config('pdf-templates.html.default_font', 'DejaVu Sans'));
        const PDF_DEFAULTS = `
            & { font-family: "${defaultFont}", "DejaVu Sans", sans-serif; font-size: 10pt; color: #000; }
            th { text-align: center; }
            [style*="text-align"] th, [align] th { text-align: inherit; }
            figure.table { display: block; margin: 0; width: auto; height: auto; }
            figure.table > table { width: auto; height: auto; border: 0; border-collapse: separate; border-spacing: 2px; }
            figure.table > table[border]:not([border="0"]) { border: 1px outset #808080; }
            figure.table > table > * > tr > :is(td, th) { border: 0; padding: 1px; min-width: 0; background: none; outline: 1px dashed rgba(128, 128, 128, .35); outline-offset: -1px; }
            figure.table > table[border]:not([border="0"]) > * > tr > :is(td, th) { border: 1px inset #808080; outline: 0; }
            figure.table > table > * > tr > :is(td, th) > p:first-of-type { margin-top: 1em; }
            figure.table > table > * > tr > :is(td, th) > p:last-of-type { margin-bottom: 1em; }`;
        const cellPadding = () => [...new Set([...html.value.matchAll(/<table[^>]*\scellpadding=["']?(\d+)/gi)].map((m) => m[1]))]
            .map((n) => `figure.table > table[cellpadding="${n}"] > * > tr > :is(td, th) { padding: ${n}px; }`).join('\n');
        // The CSS tab and the <style> blocks of the HTML (a full document keeps its CSS in <head>); @font-face and
        // @import cannot be nested, so they go to the top level, and @page means nothing on screen
        const styleBlocks = () => [...html.value.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style\s*>/gi)].map((m) => m[1]).join('\n');
        const atRules = (source) => [...source.matchAll(/@(?:font-face|import)\b[^{;]*(?:\{[^}]*\}|;)/gi)].map((m) => m[0]).join('\n');
        const scopedSource = (source) => source
            .replace(/@(?:font-face|import)\b[^{;]*(?:\{[^}]*\}|;)/gi, '')
            .replace(/@page\b[^{]*\{[^}]*\}/gi, '')
            .replace(/(^|[{},;]\s*)(?:html|body)(?=\s*[{,.:#\[\s])/g, '$1&');
        const templateCss = () => scopedSource(`${styleBlocks()}\n${css.value}`);
        const scopeCss = () => visualStyle.textContent = visualOn
            ? `${atRules(`${styleBlocks()}\n${css.value}`)}
.pt-visual .ck-content.ck-editor__editable[dir] { ${PDF_DEFAULTS}\n${cellPadding()} }
.pt-visual.pt-visual.pt-visual.pt-visual .ck-content.ck-editor__editable[dir] { ${templateCss()} }
${placeholderImages()}`
            : '';

        // dompdf has no flexbox or grid (columns here, stacked in the PDF), and places position: absolute against the
        // page, not a table cell: the editor says so instead of showing a layout the PDF will not have
        const layoutWarning = document.getElementById('pt-layout-warning');
        const checkLayout = () => {
            const source = html.value + css.value;
            const flex = /display\s*:\s*(inline-)?(flex|grid)\b/i.test(source);
            const absolute = /position\s*:\s*absolute/i.test(source);
            const chromeEngine = document.getElementById('pt-engine')?.value === 'chrome';
            layoutWarning?.classList.toggle('pt-hidden', chromeEngine || (!flex && !absolute));
            layoutWarning?.querySelector('[data-flex]')?.classList.toggle('pt-hidden', !flex);
            layoutWarning?.querySelector('[data-absolute]')?.classList.toggle('pt-hidden', !absolute);
        };
        html.addEventListener('input', checkLayout);
        css.addEventListener('input', checkLayout);
        document.getElementById('pt-engine')?.addEventListener('change', checkLayout);
        checkLayout();

        // <img src="@{{ logo_url }}">: the editing area shows the image of the test data (CSS content: url(), the HTML
        // keeps the placeholder). URLs and data: URIs only; library images (pdf-asset:) come from the image route.
        function placeholderImages() {
            let data;
            try {
                data = JSON.parse(sample.value || '{}');
            } catch {
                return '';
            }
            const doc = Array.isArray(data) ? (data[0] ?? {}) : data;
            const rules = new Set();
            for (const [, src, path] of html.value.matchAll(/<img[^>]+src=["'](\{\{?\s*(@?[\w.]+)(?:\s*\|[^}"']*)?\s*\}?\})["']/g)) {
                const value = path.split('.').reduce((v, key) => (v && typeof v === 'object' ? v[key] : undefined), doc);
                if (typeof value !== 'string') continue;
                const url = value.startsWith('pdf-asset:') ? @json($ptRoute('images.show', '__name__')).replace('__name__', encodeURIComponent(value.slice(10))) : value;
                if (!/^(https?:|data:image\/)/i.test(url)) continue;
                rules.add(`.pt-visual .ck-content img[src="${CSS.escape(src)}"] { content: url("${url.replace(/["\\\n]/g, encodeURIComponent)}"); }`);
            }

            return [...rules].join('\n');
        }

        async function setVisual(on) {
            if (on && !visual) {
                modeVisual.disabled = true;
                try {
                    const { visualEditor } = await import('pdf-templates/visual');
                    visual = await visualEditor({
                        element: document.getElementById('pt-visual-editor'),
                        url: visualConfig.url,
                        css: visualConfig.css,
                        fonts: visualFonts,
                        labels: t,
                        onChange() {
                            fromVisual = true;
                            html.value = visual.getHtml();
                            html.dispatchEvent(new Event('input'));
                            fromVisual = false;
                        },
                    });
                } catch (e) {
                    notify(t.visual_error.replace(':error', e.message), 'danger');
                    modeVisual.disabled = false;
                    modeCode.click();
                    return;
                } finally {
                    modeVisual.disabled = false;
                }
            }
            if (on) await visual.setHtml(html.value);
            visualOn = on;
            visualBox.classList.toggle('pt-hidden', !on);
            html.classList.toggle('pt-hidden', on);
            document.getElementById('pt-visual-note').classList.toggle('pt-hidden', !on);
            scopeCss();
        }
        if (modeVisual) {
            modeVisual.addEventListener('click', () => visualOn || setVisual(true));
            modeCode.addEventListener('click', () => visualOn && setVisual(false));
            css.addEventListener('input', scopeCss);
            sample.addEventListener('input', scopeCss);
            html.addEventListener('input', scopeCss);
            // The HTML changed from outside (AI answer) while the visual editor is open
            html.addEventListener('input', () => visualOn && !fromVisual && visual.setHtml(html.value));
        }

        // Image library: a click inserts <img src="pdf-asset:…"> at the cursor in the HTML (resolved on render)
        const { imageLibrary } = await import('pdf-templates/images');
        const library = imageLibrary({
            t,
            async insert(image) {
                const tag = `<img src="${image.ref}" alt="" style="width: 40mm;">`;
                (visualOn ? modeVisual : document.getElementById('pt-mode-code')).click();
                if (visualOn) {
                    // The editor shows the image itself; it is stored as the pdf-asset:… reference again
                    visual.insertHtml(await resolveImages(tag));
                    chrome.closeDrawer();
                    notify(t.image_inserted.replace(':name', image.name));
                    return;
                }
                const at = html.selectionStart ?? html.value.length;
                html.setRangeText(tag, at, html.selectionEnd ?? at, 'end');
                html.dispatchEvent(new Event('input'));
                chrome.closeDrawer();
                html.focus();
                notify(t.image_inserted.replace(':name', image.name));
            },
        });

        // External images (https://…) are not loaded by dompdf: download them into the library and point the
        // template at them
        const importButton = document.getElementById('pt-images-import');
        importButton.addEventListener('click', async () => {
            const urls = externalImages(html.value, css.value);
            if (!urls.length) {
                notify(t.images_import_none);
                return;
            }
            importButton.disabled = true;
            try {
                const result = await importExternalImages(importButton.dataset.url, urls.slice(0, 30));
                if (result.imported) {
                    html.value = result.replace(html.value);
                    css.value = result.replace(css.value);
                    html.dispatchEvent(new Event('input'));
                    library.render(result.images);
                }
                notify(t.images_imported.replace(':count', result.imported).replace(':total', urls.length), result.failed.length ? 'danger' : 'success');
                result.failed.slice(0, 3).forEach(([url, reason]) => notify(url + ': ' + reason, 'danger'));
            } catch (e) {
                notify(e.message, 'danger');
            } finally {
                importButton.disabled = false;
            }
        });

        @if ($aiEnabled)
            // AI assistant: answers land in the editor as unsaved changes
            const { assistant } = await import('pdf-templates/ai');
            const name = document.getElementById('pt-name');
            const editors = { html, css, sample_data: sample, paper, orientation, name };
            const tabOf = { html: 'pt-tab-html', css: 'pt-tab-css', sample_data: 'pt-tab-data' };
            assistant({
                url: @json($ptRoute('assistant', $template)),
                t,
                key: {{ $template->id }},
                initial: @json(session('pdf-templates.ai_history', [])),
                getDraft: () => Object.fromEntries(Object.entries(editors).map(([k, el]) => [k, el.value])),
                applyDraft(patch) {
                    Object.entries(patch).forEach(([k, value]) => {
                        if (!editors[k]) return;
                        editors[k].value = value;
                        editors[k].dispatchEvent(new Event('input'));
                    });
                    const tab = Object.keys(tabOf).find((k) => k in patch);
                    if (tab) document.querySelector(`[data-tab="${tabOf[tab]}"]`).click();
                    chrome.setDirty(true);
                    preview(true);
                },
            });
        @endif
    </script>
@endpush
