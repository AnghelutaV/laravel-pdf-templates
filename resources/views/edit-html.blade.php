@extends(config('pdf-templates.layout') ?: 'pdf-templates::layout')

@push('styles')
    @include('pdf-templates::partials.head')
@endpush

@section(config('pdf-templates.section', 'content'))
    @php
        $fonts = collect([config('pdf-templates.html.default_font', 'DejaVu Sans'), ...array_keys(config('pdf-templates.html.fonts', []))])
            ->unique()->map(fn($font) => '<code>' . e($font) . '</code>')->implode(', ');
    @endphp
    {{-- Editor first: a slim strip + code / preview; everything else lives in the off-canvas menu.
         Saved over JSON (Ctrl+S); the form is the no-JS fallback. --}}
    <form method="post" action="{{ $ptRoute('update', $template) }}" id="pt-form">
        @csrf
        @method('PUT')
        <div class="pt pt-compact pt-focus" id="pt-shell" data-csrf="{{ csrf_token() }}">
            <x-pdf-templates::editor :template="$template">
                <section class="pt-drawer-section">
                    <h3>{{ __('pdf-templates::ui.section_check') }}</h3>
                    <a class="pt-btn pt-btn-block" href="{{ $ptRoute('test', $template) }}" data-pt-leave>
                        <x-pdf-templates::icon name="flask" :size="15" /> {{ __('pdf-templates::ui.test') }}
                    </a>
                </section>

                <section class="pt-drawer-section">
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

                <section class="pt-drawer-section">
                    <h3>{{ __('pdf-templates::ui.section_file') }}</h3>
                    <a class="pt-btn pt-btn-block" href="{{ $ptRoute('export', $template) }}">
                        <x-pdf-templates::icon name="download" :size="15" /> {{ __('pdf-templates::ui.export') }}
                    </a>
                </section>
            </x-pdf-templates::editor>

            <div class="pt-split pt-even pt-workspace" id="pt-workspace">
                <div class="pt-card">
                    <div class="pt-tabs" id="pt-tabs" role="tablist">
                        <button type="button" class="pt-tab" role="tab" data-tab="pt-tab-html"><x-pdf-templates::icon name="code" :size="14" /> HTML</button>
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
        import { renderOnServer, showPdf, codeTextarea, tabs, fillViewport, notify, saveTemplate, editorChrome, fullscreenToggle } from 'pdf-templates';

        // Code + preview take the height down to the bottom of the window; stacked (natural height) on small screens
        fillViewport(document.getElementById('pt-workspace'), { gap: 8, minWidth: 1100 });

        const t = @json(__('pdf-templates::ui'));
        const renderUrl = @json($ptRoute('render', $template));
        const updateUrl = @json($ptRoute('update', $template));
        const form = document.getElementById('pt-form');
        const [html, css, sample, paper, orientation] = ['pt-html', 'pt-css', 'pt-sample', 'pt-paper', 'pt-orientation']
            .map((id) => document.getElementById(id));
        const fields = ['pt-name', 'pt-slug', 'pt-description', 'pt-active'].map((id) => document.getElementById(id));
        const status = document.getElementById('pt-status');
        const errorBox = document.getElementById('pt-error');
        let timer;
        let seq = 0;

        tabs(document.getElementById('pt-tabs'));
        [html, css, sample].forEach(codeTextarea);

        async function save() {
            const [name, slug, description, active] = fields;
            try {
                const res = await saveTemplate(updateUrl, {
                    name: name.value,
                    slug: slug.value,
                    description: description.value,
                    active: active.checked ? 1 : 0,
                    html: html.value,
                    css: css.value,
                    sample_data: sample.value,
                    paper: paper.value,
                    orientation: orientation.value,
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

        async function preview() {
            const current = ++seq;
            status.textContent = t.generating;
            try {
                const blob = await renderOnServer(renderUrl, {
                    html: html.value, css: css.value, data: sample.value, paper: paper.value, orientation: orientation.value,
                });
                if (current !== seq) return;
                showPdf(document.getElementById('pt-frame'), blob);
                errorBox.classList.add('pt-hidden');
                status.textContent = new Date().toLocaleTimeString();
            } catch (e) {
                if (current !== seq) return;
                errorBox.querySelector('[data-pt-alert-text]').textContent = e.message;
                errorBox.classList.remove('pt-hidden');
                status.textContent = '';
            }
        }

        document.getElementById('pt-preview').addEventListener('click', preview);

        // Full screen on the PDF preview alone
        fullscreenToggle(document.getElementById('pt-preview-fs'), document.getElementById('pt-preview-card'), t);
        [html, css, sample, paper, orientation].forEach((el) => el.addEventListener('input', () => {
            if (!document.getElementById('pt-auto').checked) return;
            clearTimeout(timer);
            timer = setTimeout(preview, 900);
        }));
        preview();
    </script>
@endpush
